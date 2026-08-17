<?php

declare(strict_types=1);

namespace Ladybug\Tests\Integration;

use Ladybug\Exception\QueryException;
use Ladybug\PreparedStatement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(PreparedStatement::class)]
final class ParameterTest extends IntegrationTestCase
{
    /** @return iterable<string, array{0: mixed}> */
    public static function bindableProvider(): iterable
    {
        yield 'int' => [7];
        yield 'int zero' => [0];
        yield 'int negative' => [-7];
        yield 'int max' => [PHP_INT_MAX];
        yield 'float' => [2.5];
        yield 'string' => ['abc'];
        yield 'string empty' => [''];
        yield 'string utf-8' => ['zażółć'];
        yield 'string with quotes' => ["it's \"quoted\""];
        yield 'bool true' => [true];
        yield 'bool false' => [false];
        yield 'null' => [null];
    }

    #[DataProvider('bindableProvider')]
    public function testAValueRoundTripsThroughABinding(mixed $value): void
    {
        self::assertSame($value, $this->connection->query('RETURN $v AS v', ['v' => $value])->fetchOne());
    }

    public function testSeveralParametersInOneQuery(): void
    {
        $row = $this->connection->query(
            'RETURN $i AS i, $f AS f, $s AS s, $b AS b, $n AS n',
            ['i' => 7, 'f' => 2.5, 's' => 'x', 'b' => true, 'n' => null],
        )->fetchRow();

        self::assertSame(['i' => 7, 'f' => 2.5, 's' => 'x', 'b' => true, 'n' => null], $row);
    }

    public function testAParameterIsNotInterpretedAsCypher(): void
    {
        // The whole point of binding: this must come back as text, not run as a query.
        $injection = "'; MATCH (n) DETACH DELETE n; //";

        self::assertSame($injection, $this->connection->query('RETURN $v AS v', ['v' => $injection])->fetchOne());
    }

    public function testDateTimeIsBoundAsATimestamp(): void
    {
        $when = new \DateTimeImmutable('2026-01-02 03:04:05.678901', new \DateTimeZone('UTC'));

        $value = $this->connection->query('RETURN CAST($moment AS TIMESTAMP) AS t', ['moment' => $when])->fetchOne();

        self::assertInstanceOf(\DateTimeImmutable::class, $value);
        self::assertSame('2026-01-02 03:04:05.678901', $value->format('Y-m-d H:i:s.u'));
    }

    public function testAStringableIsBoundAsAString(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'from-stringable';
            }
        };

        self::assertSame('from-stringable', $this->connection->query('RETURN $v AS v', ['v' => $stringable])->fetchOne());
    }

    public function testAPreparedStatementIsReusableWithDifferentValues(): void
    {
        $statement = $this->connection->prepare('RETURN $n * 2 AS doubled');

        self::assertSame(4, $statement->execute(['n' => 2])->fetchOne());
        self::assertSame(10, $statement->execute(['n' => 5])->fetchOne());
        self::assertSame(-2, $statement->execute(['n' => -1])->fetchOne());
    }

    public function testThePreparedStatementCacheReturnsTheSameInstance(): void
    {
        self::assertSame(
            $this->connection->prepare('RETURN $n AS n'),
            $this->connection->prepare('RETURN $n AS n'),
        );
    }

    public function testAnUnsupportedParameterTypeIsRejectedBeforeExecution(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/Cannot bind \$v: stdClass is not supported/');

        $this->connection->query('RETURN $v AS v', ['v' => new \stdClass()]);
    }

    public function testAnArrayParameterIsRejectedWithAdvice(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/Cannot bind \$v/');

        $this->connection->query('RETURN $v AS v', ['v' => [1, 2, 3]]);
    }

    /**
     * liblbug tolerates binding a name the query never mentions, so neither do we turn it
     * into an error — this test pins that behaviour down rather than asserting a throw.
     */
    public function testAnUndeclaredParameterIsIgnored(): void
    {
        self::assertSame(1, $this->connection->query('RETURN 1 AS one', ['unused' => 1])->fetchOne());
    }

    public function testAReservedWordCannotBeUsedAsAParameterName(): void
    {
        // 'when' collides with the CASE ... WHEN keyword, and the parser rejects it before
        // binding ever happens. Worth documenting: it is a confusing failure otherwise.
        $this->expectException(QueryException::class);

        $this->connection->query('RETURN $when AS v', ['when' => 1]);
    }

    public function testAClosedStatementRefusesToExecute(): void
    {
        $statement = $this->connection->prepare('RETURN $n AS n');
        $statement->close();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/closed/');

        $statement->execute(['n' => 1]);
    }

    public function testASyntaxErrorSurfacesAtPrepareTime(): void
    {
        $this->expectException(QueryException::class);

        $this->connection->prepare('RETURN $n AS'); // trailing AS, no alias
    }
}
