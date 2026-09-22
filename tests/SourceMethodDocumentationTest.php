<?php

declare(strict_types=1);

namespace Sllhsmile\HyperfLog\Tests;

use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SourceMethodDocumentationTest extends TestCase
{
    public function testEverySourceMethodHasPurposefulPhpDoc(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src'));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            $nodes = $parser->parse($source);
            self::assertNotNull($nodes, $file->getPathname());

            /** @var list<ClassMethod> $methods */
            $methods = $finder->findInstanceOf($nodes, ClassMethod::class);
            foreach ($methods as $method) {
                $where = $file->getPathname() . ':' . $method->getStartLine() . ' ' . $method->name->toString();
                $doc = $method->getDocComment();
                self::assertNotNull($doc, $where . ' 缺少 PHPDoc');

                // 参数类型标签不能代替职责说明；允许一句简短中文或英文契约。
                $lines = preg_split('/\R/', $doc->getText());
                self::assertIsArray($lines);
                $summary = '';
                foreach ($lines as $line) {
                    $line = trim($line, " \t*/");
                    if ($line !== '') {
                        $summary = $line;
                        break;
                    }
                }
                self::assertNotSame('', $summary, $where . ' 缺少职责说明');
                self::assertStringStartsNotWith('@', $summary, $where . ' 不能只写类型标签');
            }
        }
    }
}
