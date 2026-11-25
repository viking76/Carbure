<?php

use Carbure\Lib\BackupData;
use PHPUnit\Framework\TestCase;

/**
 * Datasets of a backup: selection, encoding of the exported rows, checks of the
 * imported ones (no database).
 */
class BackupDataTest extends TestCase
{
    private const TRANSACTION = [
        'id' => 12, 'uuid' => 'abc123', 'imported' => '2026-10-01 08:00:00', 'date' => '2026-10-01', 'rdate' => '2026-09-30',
        'type' => 7, 'label' => 'CB SUPERMARCHE', 'category' => 2, 'amount' => -80.5, 'card' => null, 'bank_name' => 'bnp',
        'account_number' => '00087654321', 'pointed' => true, 'user' => 'admin',
    ];

    public function testSelect()
    {
        $this->assertSame(BackupData::names(), BackupData::select(null));
        $this->assertSame(BackupData::names(), BackupData::select(''));
        // In the order of the dependencies, whatever the order asked
        $this->assertSame(['categories', 'transactions'], BackupData::select('transactions, categories'));

        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        BackupData::select('categories,users');
    }

    public function testDatasetsHoldNoSecret()
    {
        $tables = array_column(BackupData::DATASETS, 'table');
        foreach (['users', 'api_tokens', 'devices', 'oauth_clients', 'oauth_codes', 'settings', 'bank_account', 'bank_backend'] as $table) {
            $this->assertNotContains($table, $tables);
        }
        $this->assertSame(['user'], BackupData::userColumns('transactions'));
        $this->assertSame(['user_id'], BackupData::userColumns('deleted_transactions'));
        $this->assertSame([], BackupData::userColumns('categories'));
    }

    public function testEncode()
    {
        $row = BackupData::encode('transactions', [
            'id' => '12', 'uuid' => '1234', 'imported' => '2026-10-01 08:00:00', 'date' => '2026-10-01', 'rdate' => '2026-09-30',
            'type' => '7', 'label' => '2024', 'category' => '2', 'amount' => '-80.50', 'card' => null, 'bank_name' => 'bnp',
            'account_number' => '00087654321', 'pointed' => '1', 'user' => 'admin',
        ]);
        $this->assertSame(12, $row['id']);
        // Text stays text, even when it looks like a number
        $this->assertSame('1234', $row['uuid']);
        $this->assertSame('2024', $row['label']);
        $this->assertSame('00087654321', $row['account_number']);
        $this->assertSame(-80.5, $row['amount']);
        $this->assertTrue($row['pointed']);
        $this->assertNull($row['card']);
    }

    public function testValidate()
    {
        $values = BackupData::validate('transactions', [self::TRANSACTION, ['id' => '13', 'pointed' => 0, 'amount' => '5'] + self::TRANSACTION]);
        // split_of, category_source and comment: absent from the backups made before the split
        // of transactions, the origin of the categories and the comments
        $this->assertSame([12, null, 'abc123', '2026-10-01 08:00:00', '2026-10-01', '2026-09-30', 7, 'CB SUPERMARCHE', 2, '-80.50',
            null, 'bnp', '00087654321', 1, 'admin', null, null], $values[0]);
        $this->assertSame([13, 0, '5.00'], [$values[1][0], $values[1][13], $values[1][9]]);
        $this->assertSame(12, BackupData::validate('transactions', [['id' => '14', 'split_of' => '12'] + self::TRANSACTION])[0][1]);
        $this->assertSame('manual', BackupData::validate('transactions', [['category_source' => 'manual'] + self::TRANSACTION])[0][15]);
        try {
            BackupData::validate('transactions', [['category_source' => 'import'] + self::TRANSACTION]);
            $this->fail('Unknown origin accepted');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }
        // Comment of a transaction: kept as written, 500 characters at most
        $this->assertSame('Remboursé par Paul', BackupData::validate('transactions', [['comment' => 'Remboursé par Paul'] + self::TRANSACTION])[0][16]);
        try {
            BackupData::validate('transactions', [['comment' => str_repeat('é', 501)] + self::TRANSACTION]);
            $this->fail('Comment too long accepted');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }

        // Insight alerts: a threshold beyond DECIMAL(10,2), no month alerted yet
        $this->assertSame([['user', 1, 'above', '1234567890.50', null]], BackupData::validate('insight_alerts',
            [['user_id' => 'user', 'insight_id' => 1, 'direction' => 'above', 'threshold' => 1234567890.5, 'notified_month' => null]]));

        $this->assertSame([], BackupData::validate('budgets', []));
        $this->assertSame([[0, 'Aucune', 0, 'HORS-BUDGET', 'help', 'grey']],
            BackupData::validate('categories', [['id' => 0, 'name' => 'Aucune', 'parent_category' => 0, 'type' => 'HORS-BUDGET', 'icon' => 'help', 'color' => 'grey']]));
        $this->assertSame([['u1', '2026-01-01 00:00:00', null]],
            BackupData::validate('deleted_transactions', [['uuid' => 'u1', 'deleted_at' => '2026-01-01 00:00:00', 'user_id' => null]]));
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function invalidRows(): array
    {
        return [
            'not a list' => ['budgets', ['a' => []], 'budgets: a list of rows is expected'],
            'not an object' => ['budgets', [42], 'budgets, row 1: an object is expected'],
            'missing column' => ['budgets', [['id' => 1, 'category' => 1, 'amount' => 10]], 'budgets, row 1: invalid date'],
            'bad date' => ['budgets', [['id' => 1, 'category' => 1, 'amount' => 10, 'date' => '2026-02-30']], 'invalid date'],
            'bad amount' => ['budgets', [['id' => 1, 'category' => 1, 'amount' => 'ten', 'date' => '2026-02-01']], 'invalid amount'],
            'too large' => ['budgets', [['id' => 1, 'category' => 1, 'amount' => 1e9, 'date' => '2026-02-01']], 'invalid amount'],
            'negative id' => ['budgets', [['id' => -1, 'category' => 1, 'amount' => 1, 'date' => '2026-02-01']], 'invalid id'],
            'category too big' => ['budgets', [['id' => 1, 'category' => 256, 'amount' => 1, 'date' => '2026-02-01']], 'invalid category'],
            'repeated key' => ['budgets', [['id' => 1, 'category' => 1, 'amount' => 1, 'date' => '2026-02-01'],
                ['id' => 1, 'category' => 2, 'amount' => 1, 'date' => '2026-02-01']], 'budgets: repeated id: 1'],
            'bad type' => ['categories', [['id' => 1, 'name' => 'X', 'parent_category' => 0, 'type' => 'OTHER', 'icon' => '', 'color' => '']], 'invalid type'],
            'empty name' => ['categories', [['id' => 1, 'name' => '', 'parent_category' => 0, 'type' => 'DEBIT', 'icon' => '', 'color' => '']], 'invalid name'],
            'bad flag' => ['rules', [['id' => 1, 'keyword' => 'EDF', 'category' => 1, 'notify' => 'yes']], 'invalid notify'],
            'long label' => ['transactions', [['label' => str_repeat('x', 301)] + self::TRANSACTION], 'invalid label'],
            'bad datetime' => ['transactions', [['imported' => '2026-10-01'] + self::TRANSACTION], 'invalid imported'],
            'bad user' => ['transactions', [['user' => ['admin']] + self::TRANSACTION], 'invalid user'],
            'long user' => ['deleted_transactions', [['uuid' => 'u1', 'deleted_at' => '2026-01-01 00:00:00', 'user_id' => str_repeat('u', 21)]], 'invalid user_id'],
            'repeated uuid' => ['deleted_transactions', [['uuid' => 'u1', 'deleted_at' => '2026-01-01 00:00:00', 'user_id' => null],
                ['uuid' => 'u1', 'deleted_at' => '2026-01-02 00:00:00', 'user_id' => null]], 'deleted_transactions: repeated uuid: u1'],
            'impossible datetime' => ['transactions', [['imported' => '2026-02-29 10:00:00'] + self::TRANSACTION], 'invalid imported'],
            'number as date' => ['budgets', [['id' => 1, 'category' => 1, 'amount' => 1, 'date' => 20260201]], 'invalid date'],
            'float id' => ['budgets', [['id' => 1.5, 'category' => 1, 'amount' => 1, 'date' => '2026-02-01']], 'invalid id'],
            'bool amount' => ['budgets', [['id' => 1, 'category' => 1, 'amount' => true, 'date' => '2026-02-01']], 'invalid amount'],
            'invalid UTF-8' => ['transactions', [['label' => "CB \xff"] + self::TRANSACTION], 'invalid label'],
            'bad direction' => ['insight_alerts', [['user_id' => 'user', 'insight_id' => 1, 'direction' => 'equal', 'threshold' => 1]], 'invalid direction'],
            'threshold too big' => ['insight_alerts', [['user_id' => 'user', 'insight_id' => 1, 'direction' => 'below', 'threshold' => 1e10]], 'invalid threshold'],
            'repeated alert' => ['insight_alerts', [['user_id' => 'user', 'insight_id' => 1, 'direction' => 'above', 'threshold' => 1],
                ['user_id' => 'user', 'insight_id' => 1, 'direction' => 'below', 'threshold' => 2]], 'insight_alerts: repeated user_id,insight_id: user,1'],
            'unknown' => ['users', [], 'Unknown dataset: users'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRows')]
    public function testInvalidRows(string $dataset, mixed $rows, string $message)
    {
        try {
            BackupData::validate($dataset, $rows);
            $this->fail('Accepted');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testNullableValues()
    {
        $this->assertNull(BackupData::value('?string:19', null));
        $this->assertSame('1234', BackupData::value('?string:19', 1234));
        $this->assertSame(5, BackupData::value('int:255', '5'));
        $this->assertSame(1, BackupData::value('bool', '1'));
        $this->assertNull(BackupData::value('user', null));
        $this->assertNull(BackupData::value('?user', null));
        $this->assertSame('admin', BackupData::value('?user', 'admin'));

        $this->expectException(InvalidArgumentException::class);
        BackupData::value('string:5', null);
    }
}
