<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;

class ChatController extends Controller
{
    private const UPLOAD_DIR = __DIR__ . '/../../storage/uploads';
    private const MAX_BYTES = 10485760; // 10 МБ

    /** Беседы пользователя с заголовком и флагом непрочитанного. */
    private function conversationsFor(int $uid): array
    {
        $convs = Database::all(
            "SELECT c.*, m.last_read_id,
                    (SELECT MAX(id) FROM messages msg WHERE msg.conversation_id = c.id) AS last_msg_id,
                    (SELECT body FROM messages msg WHERE msg.conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_body
               FROM conversations c
               JOIN conversation_members m ON m.conversation_id = c.id AND m.user_id = ?
              ORDER BY last_msg_id DESC",
            [$uid]
        );
        foreach ($convs as &$c) {
            $c['display'] = $this->convTitle($c, $uid);
            $c['unread'] = ((int) $c['last_msg_id'] > (int) $c['last_read_id']);
        }
        unset($c);
        return $convs;
    }

    /** JSON-состояние для всплывающего виджета: беседы, уведомления, счётчики. */
    public function widgetState(): void
    {
        Auth::requireLogin();
        $uid = (int) Auth::id();

        $list = [];
        foreach ($this->conversationsFor($uid) as $c) {
            $list[] = [
                'id'      => (int) $c['id'],
                'type'    => $c['type'],
                'display' => $c['display'],
                'unread'  => $c['unread'],
                'last'    => mb_strimwidth((string) $c['last_body'], 0, 46, '…'),
            ];
        }
        $notifs = Database::all(
            'SELECT id, title, body, is_read, created_at FROM notifications WHERE employee_id = ? ORDER BY id DESC LIMIT 15',
            [$uid]
        );
        $this->json([
            'chatUnread'    => self::unreadCount($uid),
            'notifUnread'   => \App\Services\NotificationService::unreadCount($uid),
            'conversations' => $list,
            'notifications' => $notifs,
        ]);
    }

    /** Список бесед пользователя. */
    public function index(): void
    {
        Auth::requireLogin();
        $uid = (int) Auth::id();
        $convs = $this->conversationsFor($uid);

        // Для нового личного чата — список других пользователей.
        $users = Database::all('SELECT id, full_name, role FROM users WHERE id <> ? AND is_active = 1 ORDER BY full_name', [$uid]);

        $this->view('chat/index', [
            'title' => 'Чат',
            'convs' => $convs,
            'users' => $users,
            'isAdmin' => Auth::role() === 'admin',
        ]);
    }

    /** Открыть/создать личный чат с пользователем. */
    public function direct(): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $uid = (int) Auth::id();
        $other = (int) $this->input('user_id');
        if (!$other || $other === $uid || !Database::scalar('SELECT 1 FROM users WHERE id = ?', [$other])) {
            flash('Выберите собеседника.', 'error');
            $this->redirect('/chat');
        }
        $cid = (int) Database::scalar(
            "SELECT c.id FROM conversations c
               JOIN conversation_members m1 ON m1.conversation_id = c.id AND m1.user_id = ?
               JOIN conversation_members m2 ON m2.conversation_id = c.id AND m2.user_id = ?
              WHERE c.type = 'direct' LIMIT 1",
            [$uid, $other]
        );
        if (!$cid) {
            $cid = Database::insert("INSERT INTO conversations (type, created_by) VALUES ('direct', ?)", [$uid]);
            Database::insert('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?,?)', [$cid, $uid]);
            Database::insert('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?,?)', [$cid, $other]);
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
            $name = (string) Database::scalar('SELECT full_name FROM users WHERE id = ?', [$other]);
            $this->json(['ok' => true, 'id' => $cid, 'display' => $name]);
        }
        $this->redirect('/chat/' . $cid);
    }

    /** Список контактов для нового чата (JSON, для виджета). */
    public function contacts(): void
    {
        Auth::requireLogin();
        $uid = (int) Auth::id();
        // SQLite LOWER() не понижает кириллицу — ищем по исходному регистру (LIKE по подстроке).
        $q = trim((string) $this->input('q'));
        $where = 'u.id <> ? AND u.is_active = 1';
        $params = [$uid];
        if ($q !== '') { $where .= ' AND u.full_name LIKE ?'; $params[] = "%$q%"; }
        $users = Database::all(
            "SELECT u.id, u.full_name, d.name AS dept FROM users u LEFT JOIN departments d ON d.id = u.department_id
              WHERE $where ORDER BY u.full_name LIMIT 200", $params);
        $this->json(['users' => $users]);
    }

    /** Создать групповой чат (только админ). */
    public function createGroup(): void
    {
        Auth::requireRole('admin');
        Auth::verifyCsrf();
        $title = trim((string) $this->input('title'));
        $members = $_POST['members'] ?? [];
        if ($title === '' || !is_array($members) || count($members) < 1) {
            flash('Укажите название и хотя бы одного участника.', 'error');
            $this->redirect('/chat');
        }
        $cid = Database::insert("INSERT INTO conversations (type, title, created_by) VALUES ('group', ?, ?)", [$title, Auth::id()]);
        $ids = array_unique(array_merge([(int) Auth::id()], array_map('intval', $members)));
        foreach ($ids as $m) {
            Database::insert('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?,?)', [$cid, $m]);
        }
        flash('Групповой чат создан.');
        $this->redirect('/chat/' . $cid);
    }

    /** Просмотр беседы. */
    public function show(string $id): void
    {
        Auth::requireLogin();
        $uid = (int) Auth::id();
        $conv = $this->memberOrFail((int) $id, $uid);

        $msgs = $this->fetchMessages((int) $id, 0);
        // Отметить прочитанным.
        $maxId = (int) Database::scalar('SELECT COALESCE(MAX(id),0) FROM messages WHERE conversation_id = ?', [$id]);
        Database::run('UPDATE conversation_members SET last_read_id = ? WHERE conversation_id = ? AND user_id = ?', [$maxId, $id, $uid]);

        $members = Database::all(
            'SELECT u.full_name FROM conversation_members m JOIN users u ON u.id = m.user_id WHERE m.conversation_id = ? ORDER BY u.full_name',
            [$id]
        );

        $this->view('chat/show', [
            'title'   => $this->convTitle($conv, $uid),
            'conv'    => $conv,
            'msgs'    => $msgs,
            'members' => $members,
            'uid'     => $uid,
            'lastId'  => $maxId,
        ]);
    }

    /** Отправка сообщения (текст + опц. файл). */
    public function send(string $id): void
    {
        Auth::requireLogin();
        Auth::verifyCsrf();
        $uid = (int) Auth::id();
        $this->memberOrFail((int) $id, $uid);

        $body = trim((string) $this->input('body'));
        $hasFile = !empty($_FILES['file']['name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK;

        if ($body === '' && !$hasFile) {
            $this->redirect('/chat/' . $id);
        }

        $msgId = Database::insert(
            'INSERT INTO messages (conversation_id, sender_id, body) VALUES (?,?,?)',
            [$id, $uid, $body]
        );

        if ($hasFile) {
            if ($_FILES['file']['size'] > self::MAX_BYTES) {
                flash('Файл слишком большой (макс. 10 МБ).', 'error');
            } else {
                if (!is_dir(self::UPLOAD_DIR)) {
                    mkdir(self::UPLOAD_DIR, 0775, true);
                }
                $orig = $_FILES['file']['name'];
                $ext = pathinfo($orig, PATHINFO_EXTENSION);
                $stored = bin2hex(random_bytes(16)) . ($ext ? '.' . preg_replace('/[^A-Za-z0-9]/', '', $ext) : '');
                if (move_uploaded_file($_FILES['file']['tmp_name'], self::UPLOAD_DIR . '/' . $stored)) {
                    Database::insert(
                        'INSERT INTO message_attachments (message_id, orig_name, stored_name, mime, size_bytes) VALUES (?,?,?,?,?)',
                        [$msgId, $orig, $stored, $_FILES['file']['type'] ?? '', (int) $_FILES['file']['size']]
                    );
                }
            }
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '') {
            $this->json(['ok' => true, 'id' => (int) $msgId]);
        }
        $this->redirect('/chat/' . $id);
    }

    /** JSON-подгрузка новых сообщений (после after). */
    public function messages(string $id): void
    {
        Auth::requireLogin();
        $uid = (int) Auth::id();
        $this->memberOrFail((int) $id, $uid);
        $after = (int) $this->input('after', 0);
        $msgs = $this->fetchMessages((int) $id, $after);

        $maxId = (int) Database::scalar('SELECT COALESCE(MAX(id),0) FROM messages WHERE conversation_id = ?', [$id]);
        if ($maxId > $after) {
            Database::run('UPDATE conversation_members SET last_read_id = ? WHERE conversation_id = ? AND user_id = ?', [$maxId, $id, $uid]);
        }
        $this->json(['messages' => $msgs, 'me' => $uid]);
    }

    /** Скачивание вложения (только участник беседы). */
    public function file(string $id): void
    {
        Auth::requireLogin();
        $uid = (int) Auth::id();
        $att = Database::one(
            'SELECT a.*, m.conversation_id FROM message_attachments a JOIN messages m ON m.id = a.message_id WHERE a.id = ?',
            [$id]
        );
        if (!$att) { http_response_code(404); exit('Нет файла'); }
        $isMember = Database::scalar('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?', [$att['conversation_id'], $uid]);
        if (!$isMember) { http_response_code(403); exit('Нет доступа'); }

        $path = self::UPLOAD_DIR . '/' . $att['stored_name'];
        if (!is_file($path)) { http_response_code(404); exit('Файл не найден'); }

        header('Content-Type: ' . ($att['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . rawurlencode($att['orig_name']) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    // ---------- helpers ----------
    private function fetchMessages(int $cid, int $after): array
    {
        $rows = Database::all(
            "SELECT m.id, m.sender_id, m.body, m.created_at, u.full_name AS sender
               FROM messages m JOIN users u ON u.id = m.sender_id
              WHERE m.conversation_id = ? AND m.id > ?
              ORDER BY m.id",
            [$cid, $after]
        );
        foreach ($rows as &$m) {
            $m['attachments'] = Database::all(
                'SELECT id, orig_name, size_bytes FROM message_attachments WHERE message_id = ?',
                [$m['id']]
            );
        }
        return $rows;
    }

    private function convTitle(array $conv, int $uid): string
    {
        if ($conv['type'] === 'group') {
            return $conv['title'] ?: 'Групповой чат';
        }
        $name = Database::scalar(
            'SELECT u.full_name FROM conversation_members m JOIN users u ON u.id = m.user_id
              WHERE m.conversation_id = ? AND m.user_id <> ? LIMIT 1',
            [$conv['id'], $uid]
        );
        return $name ?: 'Личный чат';
    }

    private function memberOrFail(int $cid, int $uid): array
    {
        $conv = Database::one('SELECT * FROM conversations WHERE id = ?', [$cid]);
        $isMember = $conv && Database::scalar('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?', [$cid, $uid]);
        if (!$isMember) {
            http_response_code(403);
            echo \App\Core\View::render('errors/403', ['title' => 'Нет доступа']);
            exit;
        }
        return $conv;
    }

    /** Кол-во непрочитанных бесед (для бейджа в меню). */
    public static function unreadCount(int $uid): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM conversation_members m
              WHERE m.user_id = ?
                AND (SELECT COALESCE(MAX(id),0) FROM messages msg WHERE msg.conversation_id = m.conversation_id) > m.last_read_id",
            [$uid]
        );
    }

    /** Последние беседы пользователя для дашборда: id, заголовок, превью, флаг непрочитанного. */
    public static function recent(int $uid, int $limit = 5): array
    {
        $convs = Database::all(
            "SELECT c.id, c.type, c.title,
                    (SELECT MAX(id) FROM messages msg WHERE msg.conversation_id = c.id) AS last_msg_id,
                    (SELECT body FROM messages msg WHERE msg.conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_body,
                    m.last_read_id
               FROM conversations c
               JOIN conversation_members m ON m.conversation_id = c.id AND m.user_id = ?
              WHERE EXISTS (SELECT 1 FROM messages msg WHERE msg.conversation_id = c.id)
              ORDER BY last_msg_id DESC LIMIT ?",
            [$uid, $limit]
        );
        $out = [];
        foreach ($convs as $c) {
            if ($c['type'] === 'group') {
                $title = $c['title'] ?: 'Групповой чат';
            } else {
                $title = (string) Database::scalar(
                    'SELECT u.full_name FROM conversation_members m JOIN users u ON u.id = m.user_id
                      WHERE m.conversation_id = ? AND m.user_id <> ? LIMIT 1', [$c['id'], $uid]) ?: 'Личный чат';
            }
            $out[] = [
                'id'     => (int) $c['id'],
                'title'  => $title,
                'last'   => mb_strimwidth((string) $c['last_body'], 0, 50, '…'),
                'unread' => ((int) $c['last_msg_id'] > (int) $c['last_read_id']),
            ];
        }
        return $out;
    }
}
