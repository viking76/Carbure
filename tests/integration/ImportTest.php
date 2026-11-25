<?php

use Carbure\Lib\ApiError;
use Carbure\Lib\Db;
use Carbure\Lib\Webservice;
use Carbure\Resources\Transaction;

/**
 * Import of the statement files downloaded from the banks
 */
class ImportTest extends DatabaseTestCase
{
    /**
     * A CSV statement, base64 encoded as the portal sends it
     * @return string
     */
    private function statement()
    {
        $date = fn($days) => date('d/m/Y', strtotime($this->day($days)));
        $csv = "Date;Libellé;Montant\n"
            // u2 of the data set (PRLV SEPA EDF, -50 on day 2) with another label, one day later
            . $date(3) . ";PRLV SEPA EDF MDT/987;-50,00\n"
            // New, categorized by the SUPERMARCHE rule
            . $date(5) . ";CB SUPERMARCHE BIS;-12,34\n"
            // New and old: the rules apply whatever the date
            . "10/05/2019;VIR SALAIRE MAI;2 000,00\n"
            // Twice in the file: two payments, each with its own UUID
            . $date(5) . ";CB SUPERMARCHE BIS;-12,34\n";
        return base64_encode($csv);
    }

    public function testPreview()
    {
        $this->loginAs(self::USER);
        $preview = Transaction::previewImport($this->statement(), 'releve.csv');

        $this->assertSame('csv', $preview['format']);
        // No bank has this header: its columns were guessed
        $this->assertNull($preview['bank']);
        $this->assertSame(['new' => 3, 'known' => 0, 'duplicate' => 1], $preview['counts']);
        $this->assertSame('2019-05-10', $preview['from']);
        $this->assertSame(['duplicate', 'new', 'new', 'new'], array_column($preview['rows'], 'status'));
        $this->assertSame('PRLV SEPA EDF ', $preview['rows'][0]['match']['label']);
        $this->assertSame('CB SUPERMARCHE BIS', $preview['rows'][1]['label']);
        $this->assertSame(7, $preview['rows'][1]['type']);
        $this->assertArrayNotHasKey('uuid', $preview['rows'][1]);
    }

    public function testPreviewOfANeobankExport()
    {
        // Revolut: comma separated, dates with a time, rows not booked skipped
        $this->loginAs(self::USER);
        $file = base64_encode(file_get_contents(__DIR__ . '/../fixtures/import/revolut.csv'));
        $preview = Transaction::previewImport($file, 'revolut.csv');

        $this->assertSame('csv', $preview['format']);
        // Read with the profile of its bank
        $this->assertSame(['id' => 'revolut', 'name' => 'Revolut'], $preview['bank']);
        $this->assertSame(['new' => 3, 'known' => 0, 'duplicate' => 0], $preview['counts']);
        // Cleaned like the labels of a synchronization (upper case)
        $this->assertSame(['MONOPRIX', 'TOP-UP BY *1234', 'EXCHANGED TO USD'], array_column($preview['rows'], 'label'));
    }

    public function testImportTheNewTransactions()
    {
        $this->loginAs(self::USER);
        $result = Transaction::import($this->statement(), 'releve.csv');

        $this->assertSame(3, $result['imported']);
        $this->assertSame(3, $result['categorized']);
        $this->assertEquals(2, Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction WHERE label = 'CB SUPERMARCHE BIS'", "")['n']);
        $row = Db::queryOne("SELECT category, user, type FROM bank_transaction WHERE label = 'CB SUPERMARCHE BIS' LIMIT 1", "");
        $this->assertEquals(2, $row['category']);
        $this->assertEquals(self::USER, $row['user']);
        $this->assertEquals(7, $row['type']);
        $this->assertEquals(4, Db::queryOne("SELECT category FROM bank_transaction WHERE label = 'VIR SALAIRE MAI'", "")['category']);
        // The probable duplicate is left out
        $this->assertNull(Db::queryOne("SELECT id FROM bank_transaction WHERE label LIKE 'PRLV SEPA EDF MDT%'", ""));

        // A second import finds them
        $again = Transaction::previewImport($this->statement(), 'releve.csv');
        $this->assertSame(['new' => 0, 'known' => 3, 'duplicate' => 1], $again['counts']);
        $this->assertSame(['duplicate', 'known', 'known', 'known'], array_column($again['rows'], 'status'));
        $this->assertSame(0, Transaction::import($this->statement(), 'releve.csv')['imported']);
    }

    public function testImportOnAChosenAccount()
    {
        // #485: the transactions go to the account chosen (id 3: bank / fail)
        $this->loginAs(self::USER);
        $this->assertSame(3, Transaction::import($this->statement(), 'releve.csv', null, '3')['imported']);
        $rows = Db::select("SELECT DISTINCT bank_name, account_number FROM bank_transaction WHERE label IN ('CB SUPERMARCHE BIS', 'VIR SALAIRE MAI')", "")
            ->fetch_all(MYSQLI_ASSOC);
        $this->assertSame([['bank_name' => 'bank', 'account_number' => 'fail']], $rows);
    }

    public function testImportWithoutAccountKeepsTheNumberOfTheFile()
    {
        $this->loginAs(self::USER);
        Transaction::import($this->statement(), 'releve.csv', null, '');
        $row = Db::queryOne("SELECT bank_name, account_number FROM bank_transaction WHERE label = 'VIR SALAIRE MAI'", "");
        $this->assertNull($row['bank_name']);
        $this->assertNull($row['account_number']);
    }

    public function testImportOnAnUnknownAccount()
    {
        $this->loginAs(self::USER);
        try {
            Transaction::import($this->statement(), 'releve.csv', null, 999);
            $this->fail('Imported on an unknown account');
        } catch (ApiError $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame('account_not_found', $e->getErrorCode());
        }
        $this->assertNull(Db::queryOne("SELECT id FROM bank_transaction WHERE label = 'VIR SALAIRE MAI'", ""));
    }

    public function testDeletedTransactionNotImportedAgain()
    {
        $this->loginAs(self::USER);
        Transaction::import($this->statement(), 'releve.csv');
        $salary = Db::queryOne("SELECT id FROM bank_transaction WHERE label = 'VIR SALAIRE MAI'", "");
        Transaction::delete($salary['id']);

        // Shown as deleted in the preview, not imported again even when selected
        $preview = Transaction::previewImport($this->statement(), 'releve.csv');
        $row = $preview['rows'][2];
        $this->assertSame('VIR SALAIRE MAI', $row['label']);
        $this->assertSame('known', $row['status']);
        $this->assertTrue($row['deleted']);
        $this->assertSame(['new' => 0, 'known' => 3, 'duplicate' => 1], $preview['counts']);
        $this->assertSame(0, Transaction::import($this->statement(), 'releve.csv', [2])['imported']);
        $this->assertNull(Db::queryOne("SELECT id FROM bank_transaction WHERE label = 'VIR SALAIRE MAI'", ""));
    }

    public function testImportASelectedDuplicate()
    {
        $this->loginAs(self::USER);
        // Only the probable duplicate (index 0)
        $result = Transaction::import($this->statement(), 'releve.csv', [0]);

        $this->assertSame(1, $result['imported']);
        $this->assertEquals(2, Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction WHERE amount = -50", "")['n']);
        $this->assertNull(Db::queryOne("SELECT id FROM bank_transaction WHERE label = 'CB SUPERMARCHE BIS'", ""));
    }

    public function testImportTheSecondOfTwoIdenticalRows()
    {
        $this->loginAs(self::USER);
        // Only the second CB SUPERMARCHE BIS: it keeps the UUID of its occurrence...
        $this->assertSame(1, Transaction::import($this->statement(), 'releve.csv', [3])['imported']);

        // ...so the first one is still new, and the second one known
        $preview = Transaction::previewImport($this->statement(), 'releve.csv');
        $this->assertSame(['duplicate', 'new', 'new', 'known'], array_column($preview['rows'], 'status'));
        $this->assertSame(1, Transaction::import($this->statement(), 'releve.csv', [1])['imported']);
        $this->assertEquals(2, Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction WHERE label = 'CB SUPERMARCHE BIS'", "")['n']);
    }

    public function testInvalidFiles()
    {
        $this->loginAs(self::USER);
        try {
            Transaction::previewImport('not base64 !', 'x.csv');
            $this->fail('Not base64');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }
        $this->expectExceptionCode(422);
        Transaction::previewImport(base64_encode("nothing;to;read\n"), 'x.csv');
    }

    public function testTheFileIsNotLogged()
    {
        $this->assertSame(['file' => '***', 'filename' => 'x.csv'], Webservice::maskSensitive(['file' => 'abc', 'filename' => 'x.csv']));
    }
}
