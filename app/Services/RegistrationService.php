<?php
namespace App\Services;

use App\Core\Database;

/**
 * Регистрация документов (МосЭДО): журналы с собственными счётчиками по году, бронь номеров,
 * ручной/авто номер, дата регистрации. Без журнала — нумерация по типу+год (обратная совместимость).
 */
class RegistrationService
{
    /** Журнал документа: явный documents.journal_id, иначе журнал по умолчанию для типа. */
    public static function resolveJournal(array $doc): ?array
    {
        $jid = (int) ($doc['journal_id'] ?? 0);
        if (!$jid) {
            $jid = (int) Database::scalar('SELECT journal_id FROM doc_types WHERE id=?', [(int) ($doc['type_id'] ?? 0)]);
        }
        return $jid ? Database::one('SELECT * FROM doc_journals WHERE id=?', [$jid]) : null;
    }

    /** Следующий порядковый № в журнале за год (с учётом и документов, и броней). PG-safe (целые поля). */
    public static function nextSeq(int $journalId, int $year): int
    {
        $a = (int) Database::scalar('SELECT MAX(reg_seq) FROM documents WHERE journal_id=? AND reg_year=?', [$journalId, $year]);
        $b = (int) Database::scalar('SELECT MAX(reg_seq) FROM doc_number_reservations WHERE journal_id=? AND reg_year=?', [$journalId, $year]);
        return max($a, $b) + 1;
    }

    /** Формат рег.№: 'index/seq-YYYY' (если задан индекс дела), иначе 'prefix-seq/YY'. */
    public static function format(array $journal, int $seq, int $year): string
    {
        $idx = trim((string) ($journal['index_code'] ?? ''));
        if ($idx !== '') { return $idx . '/' . $seq . '-' . $year; }
        $pfx = trim((string) ($journal['prefix'] ?? '')) ?: 'Д';
        return $pfx . '-' . $seq . '/' . substr((string) $year, 2);
    }

    /**
     * Зарегистрировать документ.
     * $opts: journal_id?, manual_no?, reg_date? (Y-m-d), reservation_id?, by (uid).
     * @return array{ok:bool,reg_number?:string,message:string}
     */
    public static function register(int $docId, array $opts): array
    {
        $doc = Database::one('SELECT * FROM documents WHERE id=?', [$docId]);
        if (!$doc) { return ['ok' => false, 'message' => 'Документ не найден']; }
        $by = (int) ($opts['by'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $regAt = !empty($opts['reg_date']) ? (substr((string) $opts['reg_date'], 0, 10) . ' 00:00:00') : $now;
        $year = (int) substr($regAt, 0, 4);

        $jid = (int) ($opts['journal_id'] ?? 0) ?: (int) ($doc['journal_id'] ?? 0);
        if (!$jid) { $j = self::resolveJournal($doc); $jid = $j ? (int) $j['id'] : 0; }
        $journal = $jid ? Database::one('SELECT * FROM doc_journals WHERE id=?', [$jid]) : null;

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $seq = null;
        $regNumber = trim((string) ($opts['manual_no'] ?? ''));

        // занять бронь, если указана
        $resId = (int) ($opts['reservation_id'] ?? 0);
        if ($resId) {
            $r = Database::one('SELECT * FROM doc_number_reservations WHERE id=? AND (doc_id IS NULL OR doc_id=?)', [$resId, $docId]);
            if ($r) {
                $seq = (int) $r['reg_seq']; $year = (int) $r['reg_year'];
                if (!$jid) { $jid = (int) $r['journal_id']; $journal = Database::one('SELECT * FROM doc_journals WHERE id=?', [$jid]); }
                if ($regNumber === '') { $regNumber = (string) $r['reg_number']; }
                Database::run('UPDATE doc_number_reservations SET doc_id=? WHERE id=?', [$docId, $resId]);
            }
        }
        if ($seq === null && $journal) { $seq = self::nextSeq($jid, $year); }
        if ($regNumber === '') {
            $regNumber = $journal ? self::format($journal, (int) $seq, $year) : self::fallbackTypeNumber($doc, $year);
        }
        Database::run(
            'UPDATE documents SET reg_number=?, reg_seq=?, reg_year=?, journal_id=?, registered_at=?, registered_by=? WHERE id=?',
            [$regNumber, $seq, $year, $jid ?: null, $regAt, $by ?: null, $docId]);
        $pdo->commit();
        return ['ok' => true, 'reg_number' => $regNumber, 'message' => 'Зарегистрирован № ' . $regNumber];
    }

    /** Зарезервировать (забронировать) следующий номер журнала. */
    public static function reserve(int $journalId, int $byUid, ?string $note = null): array
    {
        $journal = Database::one('SELECT * FROM doc_journals WHERE id=?', [$journalId]);
        if (!$journal) { return ['ok' => false, 'message' => 'Журнал не найден']; }
        $year = (int) date('Y');
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        $seq = self::nextSeq($journalId, $year);
        $no = self::format($journal, $seq, $year);
        Database::insert(
            'INSERT INTO doc_number_reservations (journal_id, reg_year, reg_seq, reg_number, reserved_by, note) VALUES (?,?,?,?,?,?)',
            [$journalId, $year, $seq, $no, $byUid, (string) ($note ?? '')]);
        $pdo->commit();
        return ['ok' => true, 'reg_number' => $no, 'message' => 'Зарезервирован № ' . $no];
    }

    /** Нумерация без журнала — по типу+год (как прежняя assignRegNumber). */
    private static function fallbackTypeNumber(array $doc, int $year): string
    {
        $type = Database::one('SELECT * FROM doc_types WHERE id=?', [(int) $doc['type_id']]);
        $n = 1 + (int) Database::scalar(
            "SELECT COUNT(*) FROM documents WHERE type_id=? AND reg_number IS NOT NULL AND substr(" . Database::txt('COALESCE(registered_at, finished_at, created_at)') . ",1,4)=?",
            [(int) $doc['type_id'], (string) $year]);
        $idx = trim((string) ($type['journal_index'] ?? ''));
        return $idx !== '' ? $idx . '/' . $n . '-' . $year : ((string) ($type['prefix'] ?? 'Д') ?: 'Д') . '-' . $n . '/' . substr((string) $year, 2);
    }
}
