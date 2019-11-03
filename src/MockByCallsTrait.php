<?php

declare(strict_types=1);

namespace Chubbyphp\Mock;

use Chubbyphp\Mock\Argument\ArgumentInterface;
use PHPUnit\Framework\MockObject\MockObject;

trait MockByCallsTrait
{
    /**
     * @param string[]|string $class
     * @param Call[]          $calls
     */
    private function getMockByCalls($class, array $calls = []): MockObject
    {
        $mock = $this->prepareMock($class);

        $mockName = (new \ReflectionObject($mock))->getShortName();

        $className = $this->getMockClassAsString($class);

        $options = JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        $callIndex = -1;

        $mock->expects(self::exactly(count($calls)))->method(self::anything())->willReturnCallback(
            function () use ($className, $mock, $mockName, &$callIndex, &$calls, $options) {
                ++$callIndex;

                $call = array_shift($calls);

                $method = $call->getMethod();
                $mockedMethod = $this->getMockedMethod($mockName);

                if ($mockedMethod !== $method) {
                    self::fail(
                        sprintf(
                            'Call at index %d on class "%s" expected method "%s", "%s" given',
                            $callIndex,
                            $className,
                            $method,
                            $mockedMethod
                        )
                        .PHP_EOL
                        .json_encode($this->getStackTrace($mock), $options)
                    );
                }

                return $this->getMockCallback($className, $callIndex, $call, $mock)(...func_get_args());
            }
        );

        return $mock;
    }

    /**
     * @param string[]|string $class
     */
    private function prepareMock($class): MockObject
    {
        $mockBuilder = $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->disableOriginalClone()
        ;

        return $mockBuilder->getMock();
    }

    /**
     * @param string[]|string $class
     */
    private function getMockClassAsString($class): string
    {
        if (is_array($class)) {
            return implode('|', $class);
        }

        return $class;
    }

    private function getMockedMethod(string $mockName): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $trace) {
            if ($mockName === $trace['class']) {
                return $trace['function'];
            }
        }
    }

    private function getMockCallback(
        string $class,
        int $callIndex,
        Call $call,
        MockObject $mock
    ): \Closure {
        return function () use ($class, $callIndex, $call, $mock) {
            if ($call->hasWith()) {
                $this->compareArguments($class, $call->getMethod(), $callIndex, $call->getWith(), func_get_args());
            }

            if (null !== $exception = $call->getException()) {
                throw $exception;
            }

            if ($call->hasReturnSelf()) {
                return $mock;
            }

            if ($call->hasReturn()) {
                return $call->getReturn();
            }

            if ($call->hasReturnCallback()) {
                $callback = $call->getReturnCallback();

                return $callback(...func_get_args());
            }
        };
    }

    private function compareArguments(
        string $class,
        string $method,
        int $at,
        array $expectedArguments,
        array $arguments
    ) {
        $expectedArgumentsCount = count($expectedArguments);
        $argumentsCount = count($arguments);

        self::assertSame(
            $expectedArgumentsCount,
            $argumentsCount,
            sprintf(
                'Method "%s" on class "%s" at call %d, got %d arguments, but %d are expected',
                $method,
                $class,
                $at,
                $expectedArgumentsCount,
                $argumentsCount
            )
        );

        foreach ($expectedArguments as $index => $expectedArgument) {
            if ($expectedArgument instanceof ArgumentInterface) {
                $expectedArgument->assert(
                    $arguments[$index],
                    ['class' => $class, 'method' => $method, 'at' => $at, 'index' => $index]
                );

                continue;
            }

            self::assertSame(
                $expectedArgument,
                $arguments[$index],
                sprintf(
                    'Method "%s" on class "%s" at call %d, argument %d',
                    $method,
                    $class,
                    $at,
                    $index
                )
            );
        }
    }

    private function getStackTrace(MockObject $mock): array
    {
        $mockName = (new \ReflectionObject($mock))->getShortName();

        $trace = [];
        $enableTrace = false;
        foreach (debug_backtrace() as $i => $row) {
            if (isset($row['class']) && $mockName === $row['class']) {
                $enableTrace = true;
            }

            if ($enableTrace) {
                $traceRow = '';

                if (isset($row['class'])) {
                    $traceRow .= $row['class'];
                }

                if (isset($row['type'])) {
                    $traceRow .= $row['type'];
                }

                if (isset($row['function'])) {
                    $traceRow .= $row['function'];
                }

                if (isset($row['file'])) {
                    $traceRow .= sprintf(' (%s:%d)', $row['file'], $row['line']);
                }

                $trace[] = $traceRow;
            }
        }

        krsort($trace);

        return array_values($trace);
    }
}
