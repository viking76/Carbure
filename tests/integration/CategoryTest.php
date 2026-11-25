<?php

use Carbure\Lib\ApiError;
use Carbure\Lib\Db;
use Carbure\Resources\Category;

class CategoryTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Categories and rules are managed by administrators
        $this->loginAs(self::ADMIN);
    }

    public function testUpdateAndCountRule()
    {
        $rule = Category::createKeyword('carrefour', 2, true);
        $this->assertSame(1, $rule['notify']);
        $this->assertEquals(1, array_column(Category::getKeywords(), 'notify', 'keyword')['CARREFOUR']);

        $updated = Category::updateKeyword($rule['id'], 'cb super', 2, false);
        $this->assertSame('CB SUPER', $updated['keyword']);
        $this->assertSame(0, $updated['notify']);

        // The data set has transactions labelled "CB SUPERMARCHE", in category 2
        $count = Category::countKeyword($rule['id']);
        $expected = (int)Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction WHERE label LIKE '%CB SUPER%'", "")['n'];
        $this->assertSame($expected, $count['matching']);
        $this->assertGreaterThanOrEqual(1, $count['categorized']);
        $this->assertLessThanOrEqual($count['matching'], $count['categorized']);

        try {
            Category::updateKeyword($rule['id'], 'EDF', 2);
            $this->fail('Duplicate keyword accepted');
        } catch (Error $e) {
            $this->assertSame(409, $e->getCode());
            // The stable code that the portal translates
            $this->assertInstanceOf(ApiError::class, $e);
            $this->assertSame('keyword_exists', $e->getErrorCode());
        }
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Category::countKeyword(9999);
    }

    public function testUserCannotManageCategoriesAndRules()
    {
        $this->loginAs(self::USER);
        $this->assertCount(5, Category::get());
        $this->assertAdminOnly(['POST /category', 'PUT /category', 'DELETE /category', 'POST /category/keyword',
            'DELETE /category/keyword', 'POST /category/keyword/apply', 'PUT /category/keyword', 'GET /category/keyword/count',
            'PUT /category/keyword/settings']);
        // Read by every user: off by default
        $this->assertSame(['keep_categorized' => false], json_decode($this->callRoute('GET', '/category/keyword/settings'), true));

        // Refused before the method is called: the category is still there
        try {
            $this->callRoute('DELETE', '/category', ['id' => 1]);
            $this->fail('Deleted by a user');
        } catch (Error $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertCount(5, Category::get());
    }

    public function testGet()
    {
        $this->assertCount(5, Category::get());
    }

    public function testCreateUpdateDelete()
    {
        $category = Category::create('Vacances', 'DEBIT', 0, 'airplane', 'teal');
        $this->assertGreaterThan(4, $category['id']);

        $child = Category::create('Hôtels', 'DEBIT', $category['id'], 'house', '');
        $this->assertSame($category['id'], $child['parent_category']);

        $updated = Category::update($child['id'], 'Hébergement', 'DEBIT', $category['id'], 'house', '#ff0000');
        $this->assertSame('Hébergement', $updated['name']);

        // A category with sub-categories cannot be deleted
        try {
            Category::delete($category['id']);
            $this->fail('Deleted with a sub-category');
        } catch (Error $e) {
            $this->assertSame(409, $e->getCode());
        }

        $this->assertTrue(Category::delete($child['id']));
        $this->assertTrue(Category::delete($category['id']));
        $this->assertCount(5, Category::get());
    }

    public function testDeleteMovesTransactionsAndRemovesBudgetsAndRules()
    {
        // Énergie (3): transaction u2, a budget and the EDF rule
        $this->assertTrue(Category::delete(3));

        $this->assertEquals(0, Db::queryOne("SELECT category FROM bank_transaction WHERE uuid = 'u2'", "")['category']);
        $this->assertEquals(0, Db::queryOne("SELECT COUNT(*) AS n FROM budget WHERE category = 3", "")['n']);
        $this->assertNull(Category::find('PRLV SEPA EDF '));
    }

    public function testValidation()
    {
        foreach ([
            fn() => Category::create('', 'DEBIT'),
            fn() => Category::create('X', 'OTHER'),
            fn() => Category::create('X', 'DEBIT', 0, 'bad icon!'),
            fn() => Category::create('X', 'DEBIT', 2),              // 2 is already a sub-category
            fn() => Category::update(1, 'Alimentation', 'DEBIT', 4), // 1 has sub-categories
            fn() => Category::update(0, 'X', 'DEBIT'),               // protected
        ] as $i => $call) {
            try {
                $call();
                $this->fail("Case $i accepted");
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode(), "Case $i: " . $e->getMessage());
            }
        }

        $this->expectException(Error::class);
        $this->expectExceptionCode(409);
        Category::create('Salaire', 'CREDIT');
    }

    public function testKeywords()
    {
        $rules = Category::getKeywords();

        $this->assertCount(3, $rules);
        $this->assertEqualsCanonicalizing(['Énergie', 'Salaire', 'Supermarché'], array_column($rules, 'category_name'));
    }

    public function testCreateAndDeleteKeyword()
    {
        $rule = Category::createKeyword('  bio ', 1);
        $this->assertSame('BIO', $rule['keyword']);

        // Applied at once to the history: "CB 100% BIO" (category 0) is categorized
        $this->assertSame(1, $rule['applied']);
        $this->assertEquals(1, Db::queryOne("SELECT category FROM bank_transaction WHERE uuid='u4'", "")['category']);
        $this->assertSame(['updated' => 0], Category::applyKeywords());

        $this->assertTrue(Category::deleteKeyword($rule['id']));
        $this->assertCount(3, Category::getKeywords());
    }

    public function testRulesApplyToCategorizedTransactions()
    {
        // "CB SUPERMARCHE" (u1) is in Supermarché (2); a rule moves it, categorized or not
        $rule = Category::createKeyword('CB SUPER', 3);
        $this->assertGreaterThanOrEqual(1, $rule['applied']);
        $this->assertEquals(3, Db::queryOne("SELECT category FROM bank_transaction WHERE uuid='u1'", "")['category']);

        // Modified rule: applied again
        $updated = Category::updateKeyword($rule['id'], 'CB SUPER', 1);
        $this->assertGreaterThanOrEqual(1, $updated['applied']);
        $this->assertEquals(1, Db::queryOne("SELECT category FROM bank_transaction WHERE uuid='u1'", "")['category']);

        // Resynchronize: every rule on every transaction (the first matching rule wins)
        Db::query("UPDATE bank_transaction SET category = 4 WHERE uuid = 'u2'");
        $this->assertGreaterThanOrEqual(1, Category::applyKeywords()['updated']);
        $this->assertEquals(3, Db::queryOne("SELECT category FROM bank_transaction WHERE uuid='u2'", "")['category']);
    }

    public function testKeepCategorizedSetting()
    {
        $this->assertSame(['keep_categorized' => false], Category::keywordSettings());
        // Coerced like a query string value: "false" is false, "1" is true
        $this->assertSame(['keep_categorized' => true], Category::updateKeywordSettings('1'));
        $this->assertSame(['keep_categorized' => true], json_decode($this->callRoute('GET', '/category/keyword/settings'), true));
        $this->assertSame(['keep_categorized' => false], Category::updateKeywordSettings('false'));
        $this->assertSame(['keep_categorized' => true], json_decode($this->callRoute('PUT', '/category/keyword/settings', ['keep_categorized' => 'true']), true));
        $this->assertTrue(Category::keepCategorized());
    }

    public function testRulesKeepCategorizedTransactions()
    {
        Category::updateKeywordSettings(true);
        Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, card, pointed, user) VALUES
            ('u6', ?, ?, 7, 'CB SUPERMARCHE NOUVEAU', 0, -20.00, '', 0, 1)", "ss", $this->day(4), $this->day(4));
        $category = fn($uuid) => (int)Db::queryOne("SELECT category FROM bank_transaction WHERE uuid=?", "s", $uuid)['category'];

        // New rule: only the uncategorized "CB SUPERMARCHE NOUVEAU" (u6) takes it
        $rule = Category::createKeyword('CB SUPER', 3);
        $this->assertSame(1, $rule['applied']);
        $this->assertSame([2, 2, 3], [$category('u1'), $category('u5'), $category('u6')]);

        // Modified rule: the one it categorized (u6) follows it, the others stay where they are
        $updated = Category::updateKeyword($rule['id'], 'CB SUPER', 1);
        $this->assertSame(1, $updated['applied']);
        $this->assertSame([2, 2, 1], [$category('u1'), $category('u5'), $category('u6')]);

        // Resynchronize: a category chosen by hand (u2) is kept, the uncategorized u6 gets the
        // first matching rule (SUPERMARCHE, created before CB SUPER)
        Db::query("UPDATE bank_transaction SET category = 4 WHERE uuid = 'u2'");
        Db::query("UPDATE bank_transaction SET category = 0 WHERE uuid = 'u6'");
        $this->assertSame(['updated' => 1], Category::applyKeywords());
        $this->assertSame([4, 2, 2], [$category('u2'), $category('u1'), $category('u6')]);

        // Turned off again: the rules overwrite the categorized transactions (u2 back to EDF)
        Category::updateKeywordSettings(false);
        $this->assertSame(['updated' => 1], Category::applyKeywords());
        $this->assertSame([3, 2, 2], [$category('u2'), $category('u1'), $category('u6')]);
    }

    public function testCreateDuplicateKeyword()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(409);
        Category::createKeyword('edf', 1);
    }

    public function testCreateKeywordUnknownCategory()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Category::createKeyword('NEW', 99);
    }

    public function testCreateEmptyKeyword()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Category::createKeyword('  ', 1);
    }

    public function testDeleteUnknownKeyword()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Category::deleteKeyword(99);
    }

    public function testFind()
    {
        $this->assertEquals(3, Category::find('PRLV SEPA EDF '));
        $this->assertNull(Category::find('UNKNOWN'));
    }
    public function testRuleWithAmount()
    {
        Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, card, pointed, user) VALUES
            ('p1', ?, ?, 7, 'PAYPAL EUROPE', 0, -10.00, '', 0, 1),
            ('p2', ?, ?, 7, 'PAYPAL EUROPE', 0, -42.50, '', 0, 1),
            ('p3', ?, ?, 1, 'PAYPAL REMBOURSEMENT', 0, 10.00, '', 0, 1)", "ssssss",
            $this->day(1), $this->day(1), $this->day(2), $this->day(2), $this->day(3), $this->day(3));
        $category = fn($uuid) => (int)Db::queryOne("SELECT category FROM bank_transaction WHERE uuid=?", "s", $uuid)['category'];

        // Without amount: every PAYPAL transaction
        $any = Category::createKeyword('paypal', 1);
        $this->assertNull($any['amount']);
        $this->assertSame(3, $any['applied']);

        // Same keyword with an amount (absolute value, comma accepted): only the transactions of 10.00,
        // expense and income; it wins over the rule without amount
        $ten = Category::createKeyword('PAYPAL', 3, false, '-10,00');
        $this->assertSame('10.00', $ten['amount']);
        $this->assertSame(2, $ten['applied']);
        $this->assertSame([3, 1, 3], [$category('p1'), $category('p2'), $category('p3')]);
        $this->assertSame(['matching' => 2, 'categorized' => 2], Category::countKeyword($ten['id']));
        // The rule without amount leaves the transactions of the rule with an amount
        $this->assertSame(['matching' => 1, 'categorized' => 1], Category::countKeyword($any['id']));
        $this->assertSame(0, Category::updateKeyword($any['id'], 'PAYPAL', 1)['applied']);
        $this->assertSame([3, 1, 3], [$category('p1'), $category('p2'), $category('p3')]);

        // Listed with their amount (null: any amount)
        $rules = array_values(array_filter(Category::getKeywords(), fn($rule) => $rule['keyword'] === 'PAYPAL'));
        $this->assertEqualsCanonicalizing([null, '10.00'], array_column($rules, 'amount'));

        // Matching: the rules with an amount first, to the cent, whatever the sign
        $this->assertSame(3, Category::find('PAYPAL EUROPE', null, -10));
        $this->assertSame(3, Category::find('PAYPAL EUROPE', null, '10.00'));
        $this->assertSame(1, Category::find('PAYPAL EUROPE', null, -10.01));
        $this->assertSame(1, Category::find('PAYPAL EUROPE'));

        // Resynchronize: same result
        Db::query("UPDATE bank_transaction SET category = 0 WHERE uuid LIKE 'p%'");
        Category::applyKeywords();
        $this->assertSame([3, 1, 3], [$category('p1'), $category('p2'), $category('p3')]);

        // Update without amount (older apps): the amount is kept; '' removes it
        $kept = Category::updateKeyword($ten['id'], 'PAYPAL', 3);
        $this->assertSame('10.00', $kept['amount']);
        // Over HTTP, a number (JSON_NUMERIC_CHECK)
        $this->assertEquals(10, json_decode($this->callRoute('PUT', '/category/keyword', ['id' => $ten['id'], 'keyword' => 'PAYPAL', 'category' => 3]), true)['amount']);
        $this->assertSame('12.00', Category::updateKeyword($ten['id'], 'PAYPAL', 3, false, 12)['amount']);
        try {
            Category::updateKeyword($ten['id'], 'PAYPAL', 3, false, '');
            $this->fail('Second PAYPAL rule without amount accepted');
        } catch (ApiError $e) {
            $this->assertSame('keyword_exists', $e->getErrorCode());
        }
        Category::deleteKeyword($any['id']);
        $this->assertNull(Category::updateKeyword($ten['id'], 'PAYPAL', 3, false, '')['amount']);
    }

    public function testRuleAmountUniquenessAndValidation()
    {
        Category::createKeyword('PAYPAL', 1, false, 10);
        Category::createKeyword('PAYPAL', 3, false, 9.99);
        foreach ([['PAYPAL', '10.00', 409, 'rule_amount_exists'], ['EDF', '', 409, 'keyword_exists'], ['EDF', null, 409, 'keyword_exists'],
                  ['NEW', 'abc', 400, 'rule_amount_invalid'], ['NEW', '0', 400, 'rule_amount_invalid'],
                  ['NEW', '100000000', 400, 'rule_amount_invalid']] as [$keyword, $amount, $code, $error]) {
            try {
                Category::createKeyword($keyword, 1, false, $amount);
                $this->fail("Rule $keyword $amount accepted");
            } catch (ApiError $e) {
                $this->assertSame([$code, $error], [$e->getCode(), $e->getErrorCode()]);
            }
        }
        // The rule with an amount does not prevent the same keyword without amount
        $this->assertNull(Category::createKeyword('PAYPAL', 4)['amount']);

        // The database refuses a second rule without amount for a keyword (a unique index accepts several NULLs)
        $this->expectException(Throwable::class);
        Db::query("INSERT INTO bank_transaction_category_keyword (keyword, category) VALUES ('PAYPAL', 1)");
    }

    public function testBeforeTheMigrationTheRulesHaveNoAmount()
    {
        Db::query("ALTER TABLE bank_transaction_category_keyword DROP INDEX keyword, DROP COLUMN amount_key, DROP COLUMN amount, ADD UNIQUE KEY keyword (keyword)");
        $ready = new ReflectionProperty(Category::class, 'amountReady');
        $ready->setValue(null, null);
        try {
            $this->assertNull(Category::getKeywords()[0]['amount']);
            $rule = Category::createKeyword('PAYPAL', 1);
            $this->assertNull(Category::updateKeyword($rule['id'], 'PAYPAL', 3)['amount']);
            $this->assertSame(['matching' => 0, 'categorized' => 0], Category::countKeyword($rule['id']));
            $this->assertSame(3, Category::find('PAYPAL', null, 10));
            try {
                Category::createKeyword('SPOTIFY', 1, false, 10);
                $this->fail('Amount accepted before the migration');
            } catch (ApiError $e) {
                $this->assertSame('rule_amount_migration', $e->getErrorCode());
            }
        } finally {
            Db::query("ALTER TABLE bank_transaction_category_keyword DROP INDEX keyword,
                ADD COLUMN amount decimal(10,2) DEFAULT NULL AFTER category,
                ADD COLUMN amount_key decimal(10,2) AS (IFNULL(amount, 0)) PERSISTENT, ADD UNIQUE KEY keyword (keyword, amount_key)");
            $ready->setValue(null, null);
        }
    }
}
