<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;

/**
 * Номенклатура дел: справочник дел со сроками хранения, списание документов «в дело»,
 * закрытие дел, электронный архив с отметкой о возможном уничтожении по истечении срока.
 */
class NomenclatureController extends Controller
{
    public const STATUS = ['open' => 'Открыто', 'closed' => 'Закрыто', 'archived' => 'В архиве'];

    private function canManage(array $me): bool
    {
        return in_array($me['role'], ['admin', 'manager'], true) || Auth::has('hr', 'accountant');
    }

    public function index(): void
    {
        Auth::requireRole('admin', 'manager', 'hr', 'accountant');
        $year = (int) ($this->input('year') ?: date('Y'));
        $cases = Database::all(
            "SELECT c.*, d.name AS dept_name,
                    (SELECT COUNT(*) FROM documents doc WHERE doc.case_id = c.id) AS docs
               FROM nomenclature_cases c LEFT JOIN departments d ON d.id = c.department_id
              WHERE c.year = ? AND c.status <> 'archived' ORDER BY c.index_code", [$year]);
        $years = array_map(fn($r) => (int) $r['y'], Database::all('SELECT DISTINCT year AS y FROM nomenclature_cases ORDER BY y DESC'));
        if (!$years) { $years = [(int) date('Y')]; }
        $this->view('nomenclature/index', [
            'title' => 'Номенклатура дел',
            'cases' => $cases,
            'year' => $year,
            'years' => $years,
            'departments' => Database::all('SELECT id, name FROM departments ORDER BY name'),
            'canManage' => $this->canManage(Auth::user()),
        ]);
    }

    public function store(): void
    {
        Auth::requireRole('admin', 'manager', 'hr');
        Auth::verifyCsrf();
        $id = (int) $this->input('id');
        $idx = trim((string) $this->input('index_code'));
        $title = trim((string) $this->input('title'));
        if ($idx === '' || $title === '') { flash('Укажите индекс и заголовок дела.', 'error'); $this->redirect('/nomenclature'); }
        $years = $this->input('storage_years') !== '' ? (int) $this->input('storage_years') : null;
        $term = trim((string) $this->input('storage_term')) ?: ($years ? $years . ' лет' : 'постоянно');
        $dept = $this->input('department_id') ? (int) $this->input('department_id') : null;
        $year = (int) ($this->input('year') ?: date('Y'));
        if ($id) {
            Database::run('UPDATE nomenclature_cases SET index_code=?, title=?, department_id=?, storage_term=?, storage_years=?, year=? WHERE id=?',
                [$idx, $title, $dept, $term, $years, $year, $id]);
            flash('Дело обновлено.');
        } else {
            Database::insert('INSERT INTO nomenclature_cases (index_code, title, department_id, storage_term, storage_years, year) VALUES (?,?,?,?,?,?)',
                [$idx, $title, $dept, $term, $years, $year]);
            flash('Дело добавлено в номенклатуру.');
        }
        $this->redirect('/nomenclature?year=' . $year);
    }

    /** Закрыть дело: фиксирует дату и рассчитывает год возможного уничтожения. */
    public function close(string $id): void
    {
        Auth::requireRole('admin', 'manager', 'hr');
        Auth::verifyCsrf();
        $c = Database::one('SELECT * FROM nomenclature_cases WHERE id = ?', [$id]);
        if (!$c) { $this->redirect('/nomenclature'); }
        $destroy = $c['storage_years'] !== null ? ((int) date('Y') + (int) $c['storage_years']) : null; // постоянно → null
        Database::run("UPDATE nomenclature_cases SET status='closed', closed_on=?, destroy_after=? WHERE id=?",
            [date('Y-m-d'), $destroy, $id]);
        flash('Дело закрыто' . ($destroy ? " (уничтожение после {$destroy} г.)" : ' (хранение постоянное)') . '.');
        $this->redirect('/nomenclature/' . (int)$id);
    }

    /** Передать дело в архив. */
    public function archive(string $id): void
    {
        Auth::requireRole('admin', 'manager', 'hr');
        Auth::verifyCsrf();
        Database::run("UPDATE nomenclature_cases SET status='archived' WHERE id=?", [$id]);
        flash('Дело передано в архив.');
        $this->redirect('/nomenclature/archive');
    }

    /** Карточка дела: реквизиты + перечень подшитых документов. */
    public function show(string $id): void
    {
        Auth::requireRole('admin', 'manager', 'hr', 'accountant');
        $c = Database::one('SELECT c.*, d.name AS dept_name FROM nomenclature_cases c LEFT JOIN departments d ON d.id=c.department_id WHERE c.id=?', [$id]);
        if (!$c) { flash('Дело не найдено.', 'error'); $this->redirect('/nomenclature'); }
        $docs = Database::all(
            "SELECT doc.id, doc.reg_number, doc.title, doc.direction, doc.filed_at, dt.name AS type_name
               FROM documents doc JOIN doc_types dt ON dt.id=doc.type_id
              WHERE doc.case_id = ? ORDER BY doc.filed_at, doc.id", [$id]);
        $this->view('nomenclature/show', [
            'title' => 'Дело ' . $c['index_code'],
            'case' => $c,
            'docs' => $docs,
            'canManage' => $this->canManage(Auth::user()),
        ]);
    }

    /** Электронный архив: закрытые/архивные дела, отметка о возможном уничтожении. */
    public function archiveList(): void
    {
        Auth::requireRole('admin', 'manager', 'hr', 'accountant');
        $thisYear = (int) date('Y');
        $cases = Database::all(
            "SELECT c.*, d.name AS dept_name,
                    (SELECT COUNT(*) FROM documents doc WHERE doc.case_id = c.id) AS docs
               FROM nomenclature_cases c LEFT JOIN departments d ON d.id = c.department_id
              WHERE c.status IN ('closed','archived') ORDER BY c.year DESC, c.index_code");
        $this->view('nomenclature/archive', [
            'title' => 'Архив дел',
            'cases' => $cases,
            'thisYear' => $thisYear,
            'canManage' => $this->canManage(Auth::user()),
        ]);
    }
}
