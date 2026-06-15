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
        Audit::log('LOGOUT', 'Выход из системы');
        Auth::logout();
        $this->redirect('/login');
    }
}
