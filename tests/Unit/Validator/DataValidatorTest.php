<?php
declare(strict_types=1);

namespace Tests\Unit\Validator;

use Core\Module\Validator\DataValidator;
use PHPUnit\Framework\TestCase;

final class DataValidatorTest extends TestCase
{
    private DataValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new DataValidator();
    }

    public function testRequiredPassesWhenValuePresent(): void
    {
        $result = $this->validator->validate(['name' => 'Ivan'], ['name' => 'required|string']);
        self::assertTrue($result->isValid());
        self::assertSame('Ivan', $result->getValidatedData()['name']);
    }

    public function testRequiredFailsWhenMissingOrEmpty(): void
    {
        $result = $this->validator->validate([], ['name' => 'required']);
        self::assertFalse($result->isValid());
        self::assertTrue($result->hasError('name'));

        $result2 = $this->validator->validate(['name' => '   '], ['name' => 'required']);
        self::assertFalse($result2->isValid(), 'Пробелы после trim считаются пустотой.');
    }

    public function testOptionalFieldAbsentIsSilentlySkipped(): void
    {
        $result = $this->validator->validate([], ['nick' => 'string|max:10']);
        self::assertTrue($result->isValid());
        self::assertArrayNotHasKey('nick', $result->getValidatedData());
    }

    public function testEmailAndUrlRules(): void
    {
        $ok = $this->validator->validate(
            ['email' => 'user@example.com', 'site' => 'https://example.com'],
            ['email' => 'required|email', 'site' => 'required|url']
        );
        self::assertTrue($ok->isValid());

        $bad = $this->validator->validate(
            ['email' => 'not-an-email', 'site' => 'notaurl'],
            ['email' => 'email', 'site' => 'url']
        );
        self::assertFalse($bad->isValid());
        self::assertCount(1, $bad->errorsFor('email'));
        self::assertCount(1, $bad->errorsFor('site'));
    }

    public function testIntCastsNumericStrings(): void
    {
        $result = $this->validator->validate(['age' => ' 42 '], ['age' => 'required|int|min:0|max:150']);
        self::assertTrue($result->isValid());
        self::assertSame(42, $result->getValidatedData()['age']);

        $bad = $this->validator->validate(['age' => 'abc'], ['age' => 'int']);
        self::assertFalse($bad->isValid());
    }

    public function testBoolCastsCommonForms(): void
    {
        foreach (['1', 'yes', 'true', 'TRUE'] as $truthy) {
            $r = $this->validator->validate(['f' => $truthy], ['f' => 'bool']);
            self::assertTrue($r->isValid());
            self::assertTrue($r->getValidatedData()['f']);
        }
        foreach (['0', 'no', 'false'] as $falsy) {
            $r = $this->validator->validate(['f' => $falsy], ['f' => 'bool']);
            self::assertTrue($r->isValid());
            self::assertFalse($r->getValidatedData()['f']);
        }
        $bad = $this->validator->validate(['f' => 'maybe'], ['f' => 'bool']);
        self::assertFalse($bad->isValid());
    }

    public function testStringLengthMinMaxBetween(): void
    {
        $r = $this->validator->validate(['p' => 'secret'], ['p' => 'min:6|max:32']);
        self::assertTrue($r->isValid());

        $short = $this->validator->validate(['p' => 'abc'], ['p' => 'min:6']);
        self::assertFalse($short->isValid());

        $between = $this->validator->validate(['n' => 5], ['n' => 'between:1-10']);
        self::assertTrue($between->isValid());

        $out = $this->validator->validate(['n' => 55], ['n' => 'between:1-10']);
        self::assertFalse($out->isValid());
    }

    public function testInAndRegexAndDate(): void
    {
        $r = $this->validator->validate(
            ['role' => 'admin', 'code' => 'AB-123', 'd' => '2026-11-01'],
            ['role' => 'in:admin,editor', 'code' => 'regex:/^[A-Z]{2}-\d{3}$/', 'd' => 'date']
        );
        self::assertTrue($r->isValid());

        $bad = $this->validator->validate(
            ['role' => 'hacker', 'code' => 'xx', 'd' => '2026-02-30'],
            ['role' => 'in:admin,editor', 'code' => 'regex:/^[A-Z]{2}-\d{3}$/', 'd' => 'date']
        );
        self::assertFalse($bad->isValid());
        self::assertTrue($bad->hasError('d'), 'Несуществующая дата должна отклоняться.');
    }

    public function testSameAndDifferent(): void
    {
        $r = $this->validator->validate(
            ['pass' => 's3cret', 'pass2' => 's3cret'],
            ['pass' => 'required', 'pass2' => 'same:pass']
        );
        self::assertTrue($r->isValid());

        $bad = $this->validator->validate(
            ['a' => 'x', 'b' => 'x'],
            ['b' => 'different:a']
        );
        self::assertFalse($bad->isValid());
    }

    public function testNullableAcceptsNullAndKeepsItInData(): void
    {
        $r = $this->validator->validate(['bio' => null], ['bio' => 'nullable|string|max:10']);
        self::assertTrue($r->isValid());
        self::assertNull($r->getValidatedData()['bio']);
    }

    public function testCustomRuleViaExtend(): void
    {
        $this->validator->extend('even', static fn ($v): ?string => ((int) $v % 2 === 0) ? null : 'Must be even.');
        $ok = $this->validator->validate(['n' => 4], ['n' => 'even']);
        self::assertTrue($ok->isValid());
        $bad = $this->validator->validate(['n' => 5], ['n' => 'even']);
        self::assertFalse($bad->isValid());
        self::assertSame(['Must be even.'], $bad->errorsFor('n'));
    }

    public function testUnknownRuleProducesErrorNotException(): void
    {
        $r = $this->validator->validate(['x' => 'v'], ['x' => 'watex']);
        self::assertFalse($r->isValid());
        self::assertStringContainsString('Unknown validation rule', $r->errorsFor('x')[0]);
    }

    public function testInvalidExtendNameRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validator->extend('Bad-Name', static fn () => null);
    }

    public function testArrayRuleAndCountSize(): void
    {
        $r = $this->validator->validate(['tags' => ['a', 'b', 'c']], ['tags' => 'array|min:2|max:5']);
        self::assertTrue($r->isValid());

        $bad = $this->validator->validate(['tags' => 'notarray'], ['tags' => 'array']);
        self::assertFalse($bad->isValid());
    }

    public function testFailedFieldExcludedFromValidatedData(): void
    {
        $r = $this->validator->validate(
            ['name' => 'ok', 'email' => 'broken'],
            ['name' => 'required|string', 'email' => 'required|email']
        );
        self::assertSame(['name' => 'ok'], $r->getValidatedData());
    }
}
