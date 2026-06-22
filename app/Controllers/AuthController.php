<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Services\Audit;

class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            $this->redirect('/');
        }
        $this->view('auth/login', ['title' => 'Вход'], false);
    }

    public function login(): void
    {
        Auth::verifyCsrf();
        $login = $this->input('login', '');
        $password = $this->input('password', '');

        if (Auth::attempt($login, $password)) {
            Audit::log('LOGIN', 'Вход в систему', ['login' => $login]);
            $this->redirect('/');
        }
        Audit::log('LOGIN_FAIL', 'Неудачный вход', ['login' => $login]);
        flash('Неверный логин или пароль.', 'error');
        $this->redirect('/login');
    }

    public function logout(): void
    {
        // Если админ работает «как сотрудник» — выход возвращает к админу, а не разрушает сессию.
        if (Auth::impostorAdminId()) {
            $this->doReturnToAdmin();
            $this->redirect('/');
        }
        Audit::log('LOGOUT', 'Выход из системы');
        Auth::logout();
        $this->redirect('/login');
    }

    /** Админ «войти как» сотрудник (только админ, POST, CSRF; без вложенности). */
    public function loginAs(string $id): void
    {
        Auth::requireRole('admin');
        Auth::verifyCsrf();
        if (Auth::impostorAdminId()) { flash('Вы уже работаете как сотрудник — сначала вернитесь к админу.', 'error'); $this->redirect('/'); }
        $targetId = (int) $id;
        if ($targetId === (int) Auth::id()) { $this->redirect('/'); }
        Audit::log('IMPERSONATE_START', 'Вход как сотрудник', ['target_id' => $targetId]);
        if (!Auth::impersonate($targetId)) { flash('Сотрудник не найден или неактивен.', 'error'); $this->redirect('/admin/employees'); }
        flash('Вы вошли как ' . ($_SESSION['name'] ?? 'сотрудник') . '. Чтобы вернуться — кнопка вверху.');
        $this->redirect('/');
    }

    /** Возврат из режима «войти как» к админу (POST, гейт по сессии impostor, не по роли). */
    public function returnToAdmin(): void
    {
        if (!Auth::impostorAdminId()) { $this->redirect('/'); }
        Auth::verifyCsrf();
        $this->doReturnToAdmin();
        $this->redirect('/');
    }

    private function doReturnToAdmin(): void
    {
        $adminId = Auth::impostorAdminId();
        if (Auth::stopImpersonating()) {
            Audit::log('IMPERSONATE_END', 'Возврат к админу', ['admin_id' => $adminId]);
            flash('Вы вернулись в свою учётную запись администратора.');
        }
    }
}
