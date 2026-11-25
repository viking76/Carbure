<?php

use Carbure\Lib\ApiError;
use Carbure\Lib\Db;
use Carbure\Lib\Migrator;
use Carbure\Resources\Backup;
use Carbure\Resources\Bank;
use Carbure\Resources\Transaction;

/**
 * Free-text comment of a transaction (PUT /transaction/comment), and its migration
 * 2026-11-19_transaction_comment on a database that already holds transactions
 */
class TransactionCommentTest extends DatabaseTestCase
{
    private const MIGRATION = '2026-11-19_transaction_comment';

    private function row($uuid)
    {
        return Db::queryOne("SELECT * FROM bank_transaction WHERE uuid = ?", "s", $uuid);
    }

    private function assertApiError($code, callable $call)
    {
        try {
            $call();
            $this->fail("No error $code");
        } catch (ApiError $e) {
            $this->assertSame($code, $e->getErrorCode());
        }
    }

    /**
     * Forget the cached state of the column (read once per request).
     */
    private function resetReady()
    {
        (new ReflectionProperty(Transaction::class, 'commentReady'))->setValue(null, null);
    }

    /**
     * Back to the schema of the database before the migration: no column, migration not recorded.
     */
    private function dropColumn()
    {
        Db::query("ALTER TABLE bank_transaction DROP COLUMN comment");
        Db::execute("DELETE FROM schema_migrations WHERE version = ?", "s", self::MIGRATION);
        $this->resetReady();
    }

    /**
     * The column and the migration back, whatever a test left (the other tests rely on them).
     */
    private function restoreColumn()
    {
        $this->resetReady();
        if (!Transaction::commentReady()) {
            Db::query("ALTER TABLE bank_transaction ADD COLUMN comment varchar(500) DEFAULT NULL AFTER label");
        }
        Db::execute("INSERT IGNORE INTO schema_migrations (version) VALUES (?)", "s", self::MIGRATION);
        $this->resetReady();
    }

    public function testSetsReplacesAndRemovesAComment()
    {
        $this->loginAs(self::USER);
        $u1 = $this->row('u1');
        $this->assertNull($u1['comment']);

        // Trimmed, returned with the transaction and its tags; the other values do not change
        $saved = Transaction::updateComment($u1['id'], "  Remboursé par Paul\nen juin  ");
        $this->assertSame("Remboursé par Paul\nen juin", $saved['comment']);
        $this->assertArrayHasKey('tags', $saved);
        $this->assertSame([$u1['label'], $u1['amount'], $u1['category'], $u1['pointed'], $u1['date'], $u1['uuid']],
            [$saved['label'], $saved['amount'], $saved['category'], $saved['pointed'], $saved['date'], $saved['uuid']]);
        $listed = array_column(Transaction::get(), 'comment', 'uuid');
        $this->assertSame("Remboursé par Paul\nen juin", $listed['u1']);
        $this->assertNull($listed['u2']);

        Transaction::updateComment($u1['id'], 'Cadeau');
        $this->assertSame('Cadeau', $this->row('u1')['comment']);

        // "", spaces only or not sent: removed
        foreach (['', '   ', null] as $empty) {
            Transaction::updateComment($u1['id'], 'X');
            $this->assertNull(Transaction::updateComment($u1['id'], $empty)['comment']);
            $this->assertNull($this->row('u1')['comment']);
        }
    }

    public function testATransactionEnteredByHandGetsItsCommentWhenCreatedOrChanged()
    {
        // A manual account is added by an administrator
        $this->loginAs(self::ADMIN);
        $cash = (int)Bank::createManual('Espèces')['id'];
        $count = fn() => (int)Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction", "")['n'];

        // Created with its comment (trimmed), or without one
        $market = Transaction::createManual($cash, $this->day(3), 'Marché', -18.5, null, '  Légumes de la semaine ');
        $this->assertSame('Légumes de la semaine', $market['comment']);
        $this->assertNull(Transaction::createManual($cash, $this->day(4), 'Pain', -1.2)['comment']);
        $this->assertNull(Transaction::createManual($cash, $this->day(4), 'Lait', -1.1, null, '  ')['comment']);

        // A comment too long enters nothing
        $before = $count();
        $this->assertApiError('transaction_comment_length',
            fn() => Transaction::createManual($cash, $this->day(5), 'Fromage', -6, null, str_repeat('x', 501)));
        $this->assertSame($before, $count());

        // Changed: not sent (mobile apps) keeps it, a new one replaces it, "" removes it
        $this->assertSame('Légumes de la semaine', Transaction::updateManual($market['id'], null, null, -20)['comment']);
        $this->assertSame('Fruits', Transaction::updateManual($market['id'], null, null, null, 'Fruits')['comment']);
        $this->assertApiError('transaction_comment_length',
            fn() => Transaction::updateManual($market['id'], null, 'Marché bio', null, str_repeat('x', 501)));
        $unchanged = $this->row($market['uuid']);
        $this->assertSame([$market['label'], 'Fruits'], [$unchanged['label'], $unchanged['comment']]);
        $this->assertNull(Transaction::updateManual($market['id'], null, null, null, '')['comment']);

        // Through the routes
        $created = json_decode($this->callRoute('POST', '/transaction',
            ['account' => $cash, 'date' => $this->day(6), 'label' => 'Kiosque', 'amount' => -3, 'comment' => 'Journal']), true);
        $this->assertSame('Journal', $created['comment']);
        $changed = json_decode($this->callRoute('PUT', '/transaction', ['id' => $created['id'], 'comment' => 'Magazine']), true);
        $this->assertSame('Magazine', $changed['comment']);
    }

    public function testTheRouteSavesTheCommentAndBumpsTheTransactionsMarker()
    {
        $this->loginAs(self::USER);
        $id = $this->row('u4')['id'];

        $saved = json_decode($this->callRoute('PUT', '/transaction/comment', ['id' => $id, 'comment' => 'Panier bio']), true);

        $this->assertSame('Panier bio', $saved['comment']);
        $this->assertSame(1, json_decode($this->callRoute('GET', '/changes'), true)['transactions']['version']);
    }

    public function testRefusesATooLongCommentOrAnUnknownTransaction()
    {
        $this->loginAs(self::USER);
        $id = $this->row('u1')['id'];

        // 500 characters (not bytes) at most
        $this->assertSame(str_repeat('é', 500), Transaction::updateComment($id, str_repeat('é', 500))['comment']);
        try {
            Transaction::updateComment($id, str_repeat('é', 501));
            $this->fail('Comment too long accepted');
        } catch (ApiError $e) {
            $this->assertSame([400, 'transaction_comment_length', ['max' => 500]], [$e->getCode(), $e->getErrorCode(), $e->getParams()]);
        }
        $this->assertSame(str_repeat('é', 500), $this->row('u1')['comment']);
        $this->assertApiError('transaction_not_found', fn() => Transaction::updateComment(999999, 'X'));
    }

    public function testTheSynchronizationAndTheImportKeepTheComment()
    {
        $this->loginAs(self::USER);
        $origin = ['bank_name' => 'bnp', 'account_number' => '111'];
        $payment = ['date' => $this->day(3), 'amount' => -42, 'raw' => 'CB PHARMACIE', 'type' => 7];
        $this->assertCount(1, Transaction::save([$payment], self::USER, false, $origin));
        $row = Db::queryOne("SELECT * FROM bank_transaction WHERE label = 'CB PHARMACIE'", "");
        Transaction::updateComment($row['id'], 'Remboursé par la mutuelle');

        // Synchronized again: merged on its UUID, the comment stays
        $this->assertCount(0, Transaction::save([$payment], self::USER, false, $origin));
        $again = Db::queryOne("SELECT * FROM bank_transaction WHERE label = 'CB PHARMACIE'", "");
        $this->assertSame([$row['id'], 'Remboursé par la mutuelle'], [$again['id'], $again['comment']]);

        // A statement imported twice: known the second time, the comment stays
        $csv = base64_encode("Date;Libellé;Montant\n" . date('d/m/Y', strtotime($this->day(5))) . ";CB LIBRAIRIE;-12,34\n");
        $this->assertSame(1, Transaction::import($csv, 'releve.csv')['imported']);
        $book = Db::queryOne("SELECT * FROM bank_transaction WHERE label = 'CB LIBRAIRIE'", "");
        Transaction::updateComment($book['id'], 'Livre scolaire');
        $this->assertSame(0, Transaction::import($csv, 'releve.csv', [0])['imported']);
        $this->assertSame('Livre scolaire', Db::queryOne("SELECT comment FROM bank_transaction WHERE label = 'CB LIBRAIRIE'", "")['comment']);
    }

    public function testThePartsOfASplitTransactionHaveTheirOwnComment()
    {
        $this->loginAs(self::USER);
        $id = (int)$this->row('u1')['id'];
        Transaction::updateComment($id, 'Courses + pharmacie');

        $rows = Transaction::split($id, [['category' => 1, 'amount' => '-30'], ['category' => 3, 'amount' => '-20']]);
        $part = (int)$rows[1]['id'];

        // The parts start without comment; the transaction keeps its own
        $this->assertSame('Courses + pharmacie', $this->row('u1')['comment']);
        $this->assertNull(Db::queryOne("SELECT comment FROM bank_transaction WHERE id = ?", "i", $part)['comment']);
        Transaction::updateComment($part, 'Pharmacie');
        $this->assertSame('Pharmacie', Db::queryOne("SELECT comment FROM bank_transaction WHERE id = ?", "i", $part)['comment']);
        $this->assertSame('Courses + pharmacie', $this->row('u1')['comment']);

        // Split again (the parts are re-created): each part keeps its comment, by its place
        Transaction::split($id, [['category' => 1, 'amount' => '-25'], ['category' => 3, 'amount' => '-15'], ['category' => 2, 'amount' => '-5']]);
        $parts = Db::select("SELECT comment FROM bank_transaction WHERE split_of = ? ORDER BY id", "i", $id)->fetch_all(MYSQLI_ASSOC);
        // $rows[1] (the original one comes first) is the first part
        $this->assertSame(['Pharmacie', null, null], array_column($parts, 'comment'));
        $this->assertSame('Courses + pharmacie', $this->row('u1')['comment']);
    }

    public function testTheSearchFindsTheComment()
    {
        $this->loginAs(self::USER);
        Transaction::updateComment($this->row('u4')['id'], 'Anniversaire de Léa');

        // Any case, a LIKE wildcard is a plain character, the label is still searched
        $this->assertSame(['u4'], array_column(Transaction::search('anniversaire'), 'uuid'));
        $this->assertSame(['u4'], array_column(Transaction::search('DE LÉA'), 'uuid'));
        $this->assertSame([], Transaction::search('anniv%léa'));
        $this->assertContains('u1', array_column(Transaction::search('supermarche'), 'uuid'));
        // With another criterion
        $this->assertSame([], Transaction::search('anniversaire', 100, null, null, 'credit'));
        // The transactions similar to one: in the label only, a comment naming the merchant is not one
        $this->assertSame([], Transaction::search('anniversaire', in: 'label'));
        Transaction::updateComment($this->row('u4')['id'], 'Remboursement SUPERMARCHE');
        $this->assertContains('u4', array_column(Transaction::search('supermarche'), 'uuid'));
        $this->assertNotContains('u4', array_column(Transaction::search('supermarche', in: 'label'), 'uuid'));
    }

    public function testTheBackupKeepsTheComment()
    {
        $this->loginAs(self::ADMIN);
        Transaction::updateComment($this->row('u2')['id'], 'Facture annuelle');
        $before = json_decode($this->callRoute('GET', '/backup/export', ['datasets' => 'transactions']), true)['datasets'];
        $this->assertSame('Facture annuelle', array_column($before['transactions'], 'comment', 'uuid')['u2']);

        Db::query("UPDATE bank_transaction SET comment = NULL");
        Backup::import($before);
        $this->assertSame('Facture annuelle', $this->row('u2')['comment']);

        // Stored like the API does: trimmed, an empty one is none
        $edited = $before['transactions'];
        foreach ($edited as &$t) {
            $t['comment'] = ['u1' => '  ', 'u2' => '  Facture  ', 'u3' => '0'][$t['uuid']] ?? $t['comment'];
        }
        unset($t);
        Backup::import(['transactions' => $edited]);
        $this->assertSame([null, 'Facture', '0'], [$this->row('u1')['comment'], $this->row('u2')['comment'], $this->row('u3')['comment']]);

        // An older backup, without comment: none
        $older = array_map(function ($t) { unset($t['comment']); return $t; }, $before['transactions']);
        Backup::import(['transactions' => $older]);
        $this->assertNull($this->row('u2')['comment']);
    }

    public function testBeforeTheMigrationTheTransactionsWorkWithoutComment()
    {
        $this->dropColumn();
        try {
            $this->loginAs(self::USER);
            $id = $this->row('u1')['id'];
            // Listed and searched as before; removing a comment is accepted (nothing to remove)
            $this->assertNotEmpty(Transaction::get());
            $this->assertContains('u1', array_column(Transaction::search('supermarche'), 'uuid'));
            $this->assertSame('u1', Transaction::updateComment($id, '')['uuid']);
            try {
                Transaction::updateComment($id, 'Trop tôt');
                $this->fail('Comment accepted before the migration');
            } catch (ApiError $e) {
                $this->assertSame([409, 'transaction_comment_migration'], [$e->getCode(), $e->getErrorCode()]);
            }
            // Entered by hand: without comment as before; with one, refused and nothing entered
            $this->loginAs(self::ADMIN);
            $cash = (int)Bank::createManual('Espèces')['id'];
            $pain = Transaction::createManual($cash, $this->day(4), 'Pain', -1.2, null, '');
            $this->assertArrayNotHasKey('comment', $pain);
            $this->assertSame('-2.00', Transaction::updateManual($pain['id'], null, null, -2, '')['amount']);
            $this->assertApiError('transaction_comment_migration',
                fn() => Transaction::createManual($cash, $this->day(4), 'Lait', -1.1, null, 'Bio'));
            $this->assertNull(Db::queryOne("SELECT id FROM bank_transaction WHERE label = 'LAIT'", ""));
            $this->assertApiError('transaction_comment_migration', fn() => Transaction::updateManual($pain['id'], null, null, null, 'X'));
            // The backup leaves the column out
            $this->loginAs(self::ADMIN);
            $export = json_decode($this->callRoute('GET', '/backup/export', ['datasets' => 'transactions']), true);
            $this->assertNull($export['datasets']['transactions'][0]['comment']);
        } finally {
            $this->restoreColumn();
        }
    }

    public function testTheMigrationKeepsTheExistingTransactions()
    {
        // Existing data: a split transaction with a tag
        $this->loginAs(self::USER);
        $id = (int)$this->row('u1')['id'];
        Transaction::split($id, [['category' => 1, 'amount' => '-30'], ['category' => 3, 'amount' => '-20']]);
        Db::query("INSERT INTO tag (id, name, color, start_date) VALUES (7, 'voyage', 'green', '2026-07-01')");
        Db::execute("INSERT INTO transaction_tag (transaction_id, tag_id) VALUES (?, 7)", "i", $id);
        $columns = "id, split_of, uuid, imported, date, rdate, type, label, category, category_source, amount, card, bank_name, account_number, pointed, user";
        $before = Db::select("SELECT $columns FROM bank_transaction ORDER BY id", "")->fetch_all(MYSQLI_ASSOC);

        // As a container started with the new image on an older database
        $this->dropColumn();
        try {
            $migrator = new Migrator(Db::getConnection());
            $this->assertContains(self::MIGRATION, $migrator->pending());
            $log = [];
            $this->assertSame([self::MIGRATION], $migrator->migrate(function ($m) use (&$log) { $log[] = $m; }));
            $this->assertSame([self::MIGRATION . ': applying...', self::MIGRATION . ': applied'], $log);
            $this->resetReady();

            // The column is there, every transaction unchanged, without comment
            $this->assertTrue(Transaction::commentReady());
            $this->assertSame($before, Db::select("SELECT $columns FROM bank_transaction ORDER BY id", "")->fetch_all(MYSQLI_ASSOC));
            $this->assertEquals(0, Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction WHERE comment IS NOT NULL", "")['n']);
            $this->assertEquals(1, Db::queryOne("SELECT COUNT(*) AS n FROM transaction_tag WHERE transaction_id = ?", "i", $id)['n']);
            $this->assertSame('Ok', Transaction::updateComment($id, 'Ok')['comment']);

            // The next start: nothing to do
            $this->assertSame([], $migrator->pending());
            $this->assertSame([], $migrator->migrate());
        } finally {
            $this->restoreColumn();
        }
    }
}
