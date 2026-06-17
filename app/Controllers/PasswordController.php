<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Services\Audit;
use App\Services\PasswordPolicy;

class PasswordController extends Controller
{
    public function form(): void
    {
        Auth::requireLogin();
        $this->view('auth/password_change', [
            'title'  => 'Смена пароля',
            'forced' => !empty($_SESSION['must_pw_change']),
            'hint'   => PasswordPolicy::hint(),
        ], false);
    }

    public function change(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $uid = (int) Auth::id();
        $new = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        if ($new !== $confirm) {
            flash('Пароли не совпадают.', 'error');
            $this->redirect('/password/change');
        }
        $err = PasswordPolicy::validate($new);
        if ($err !== null) {
            flash($err, 'error');
            $this->redirect('/password/change');
        }
        // Новый пароль должен отличаться от текущего (заданного админом).
        $cur = (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        if ($cur !== '' && password_verify($new, $cur)) {
            flash('Новый пароль должен отличаться от текущего.', 'error');
            $this->redirect('/password/change');
        }

        Database::run('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), $uid]);
        $_SESSION['must_pw_change'] = 0;
        Audit::log('PASSWORD_CHANGE', 'Смена пароля пользователем');
        flash('Пароль изменён.');
        $this->redirect('/');
    }
}
