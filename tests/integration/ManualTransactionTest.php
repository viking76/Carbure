<?php

use Carbure\Lib\ApiError;
use Carbure\Lib\Db;
use Carbure\Resources\Bank;
use Carbure\Resources\Transaction;

class ManualTransactionTest extends DatabaseTestCase
{
    private function assertApiError($code, callable $call)
    {
        try {
            $call();
            $this->fail("No error $code");
        } catch (ApiError $e) {
            $this->assertSame($code, $e->getErrorCode());
        }
    }

    private function cash()
    {
        $this->loginAs(self::ADMIN);
        return (int)Bank::createManual('Espèces')['id'];
    }

    public function testCreateAManualAccount()
    {
        $this->loginAs(self::ADMIN);
        $account = Bank::createManual('  Espèces  ');
        $this->assertSame(['Espèces', Bank::MANUAL, true], [$account['label'], $account['bank_name'], $account['manual']]);
        $this->assertMatchesRegularExpression('/^M[0-9a-f]{8}$/', $account['account_number']);

        $belgian = Bank::createManual('Banque belge', 'BE68539007547034');
        $this->assertSame('BE68539007547034', $belgian['account_number']);
        $this->assertApiError('account_already_followed', fn() => Bank::createManual('Encore', 'BE68539007547034'));
        $this->assertApiError('account_label_required', fn() => Bank::createManual(' '));
        $this->assertApiError('account_number_invalid', fn() => Bank::createManual('Compte', '-x'));

        // Listed as manual, renamed like the others, but never with a woob bank name
        $listed = array_column(Bank::get(), 'manual', 'account_number');
        $this->assertTrue($listed['BE68539007547034']);
        $this->assertSame('Belgique', Bank::update((int)$belgian['id'], 'BE68539007547034', Bank::MANUAL, 'Belgique')['label']);
        $this->assertApiError('bank_name_reserved', fn() => Bank::update((int)$belgian['id'], 'BE68539007547034', 'bnp'));
        $this->assertApiError('bank_name_reserved', fn() => Bank::create('123', Bank::MANUAL));
    }

    public function testTheManualAccountsAreNotSynchronized()
    {
        $this->cash();
        $bankIds = new ReflectionMethod(Bank::class, 'getBankIds');
        foreach ($bankIds->invoke(null) as $bankId) {
            $this->assertStringEndsNotWith('@' . Bank::MANUAL, $bankId);
        }
        $this->assertContains('111@bnp', $bankIds->invoke(null));
    }

    public function testEnterATransactionByHand()
    {
        $account = $this->cash();
        $this->loginAs(self::USER);

        // Category of the rules (SUPERMARCHE -> 2), checked, of the user who entered it
        $row = Transaction::createManual($account, $this->day(2), 'supermarche du coin', '-12.5');
        $this->assertSame(['SUPERMARCHE DU COIN', 2, '-12.50', 1, self::USER, 8, Bank::MANUAL],
            [$row['label'], $row['category'], $row['amount'], $row['pointed'], $row['user'], $row['type'], $row['bank_name']]);
        $this->assertSame([$this->day(2), $this->day(2)], [$row['date'], $row['rdate']]);
        $this->assertContains((int)$row['id'], array_map('intval', array_column(Transaction::get(), 'id')));

        // Category given, none found
        $this->assertSame(3, Transaction::createManual($account, $this->day(2), 'Marché', 20, 3)['category']);
        $this->assertSame(0, Transaction::createManual($account, $this->day(2), 'Marché', 20)['category']);
    }

    public function testEnterATransactionRefusesInvalidValues()
    {
        $account = $this->cash();
        $this->assertApiError('account_not_found', fn() => Transaction::createManual(999, $this->day(0), 'X', -1));
        $this->assertApiError('transaction_date_invalid', fn() => Transaction::createManual($account, '2026-02-30', 'X', -1));
        $this->assertApiError('transaction_date_invalid', fn() => Transaction::createManual($account, 'demain', 'X', -1));
        $this->assertApiError('transaction_label_invalid', fn() => Transaction::createManual($account, $this->day(0), "  \n ", -1));
        $this->assertApiError('transaction_label_invalid', fn() => Transaction::createManual($account, $this->day(0), str_repeat('A', 301), -1));
        $this->assertApiError('transaction_amount_invalid', fn() => Transaction::createManual($account, $this->day(0), 'X', 0));
        $this->assertApiError('transaction_amount_invalid', fn() => Transaction::createManual($account, $this->day(0), 'X', 'abc'));
        $this->assertApiError('category_not_found', fn() => Transaction::createManual($account, $this->day(0), 'X', -1, 99));
    }

    public function testEnterATransactionByHandOnASynchronizedAccount()
    {
        $this->loginAs(self::USER);
        $bnp = (int)Db::queryOne("SELECT id FROM bank_account WHERE bank_name = 'bnp' LIMIT 1", "")['id'];

        // On the account of the bank, told apart by its UUID
        $row = Transaction::createManual($bnp, $this->day(2), 'Boulangerie', -4.5);
        $this->assertSame(['bnp', '111', 1, self::USER], [$row['bank_name'], $row['account_number'], $row['pointed'], $row['user']]);
        $this->assertMatchesRegularExpression('/^' . Transaction::MANUAL_UUID . '[0-9a-f]{26}$/', $row['uuid']);
        $this->assertTrue(Transaction::enteredByHand($row));
        $this->assertSame('FOURNIL DU PORT', Transaction::updateManual((int)$row['id'], null, 'Fournil du port')['label']);

        // The synchronization of the account adds the transaction of the bank, the one entered by hand stays
        $origin = ['bank_name' => 'bnp', 'account_number' => '111'];
        $bank = ['type' => 8, 'raw' => 'FOURNIL DU PORT', 'amount' => -4.5, 'date' => $this->day(2)];
        $this->assertCount(1, Transaction::save([$bank], self::ADMIN, false, $origin));
        $this->assertCount(0, Transaction::save([$bank], self::ADMIN, false, $origin));
        $kept = Db::queryOne("SELECT uuid, label, amount, user FROM bank_transaction WHERE id = ?", "i", $row['id']);
        $this->assertSame([$row['uuid'], 'FOURNIL DU PORT', '-4.50', self::USER], array_values($kept));
        $this->assertSame(2, (int)Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction WHERE label = 'FOURNIL DU PORT'", "")['n']);

        // Found among the transactions of that account
        $listed = Transaction::search(limit: 500, accounts: '111@bnp');
        $this->assertContains((int)$row['id'], array_map('intval', array_column($listed, 'id')));
    }

    public function testChangeATransactionEnteredByHand()
    {
        $account = $this->cash();
        $id = (int)Transaction::createManual($account, $this->day(3), 'Boulangerie', -4.2, 1)['id'];

        $row = Transaction::updateManual($id, $this->day(1), null, -5);
        $this->assertSame([$this->day(1), $this->day(1), 'BOULANGERIE', '-5.00', 1], [$row['date'], $row['rdate'], $row['label'], $row['amount'], $row['category']]);
        $this->assertSame('PAIN', Transaction::updateManual($id, null, 'pain')['label']);

        // A transaction of a bank, or a part of a split one, is not changed
        $bank = (int)Db::queryOne("SELECT id FROM bank_transaction WHERE uuid = 'u1'", "")['id'];
        $this->assertApiError('transaction_not_manual', fn() => Transaction::updateManual($bank, null, 'X'));
        Transaction::split($id, [['category' => 3, 'amount' => -2]]);
        $part = (int)Db::queryOne("SELECT id FROM bank_transaction WHERE split_of = ?", "i", $id)['id'];
        $this->assertApiError('transaction_not_manual', fn() => Transaction::updateManual($part, null, 'X'));

        // Split: its amount stays, its label and date go to its parts
        $this->assertApiError('transaction_split_amount', fn() => Transaction::updateManual($id, null, null, -10));
        Transaction::updateManual($id, $this->day(2), 'Pain et gâteau');
        $this->assertSame([$this->day(2), 'PAIN ET GÂTEAU', '-2.00'],
            array_values(Db::queryOne("SELECT date, label, amount FROM bank_transaction WHERE id = ?", "i", $part)));
        $this->assertApiError('transaction_not_found', fn() => Transaction::updateManual(999999, null, 'X'));
    }
}
