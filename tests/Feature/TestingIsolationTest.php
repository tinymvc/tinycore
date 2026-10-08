<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class TestingIsolationTest extends FrameworkTestCase
{
    public function test_application_storage_is_isolated_and_cleaned(): void
    {
        $root = $this->storagePath . '/project/storage/framework/testing';
        mkdir($root . '/keep', 0700, true);
        file_put_contents($root . '/.gitignore', '*');
        file_put_contents($root . '/keep/sentinel', 'preserved');
        $paths = [];
        $environment = $_ENV;

        foreach (['success', 'failure', 'boot', 'flush'] as $mode) {
            $probe = new CoreFixtureStorageProbe($root, $mode);
            $result = $probe->runTest('test_storage');
            $paths[] = $probe->allocatedPath;
            $this->assertCount($mode === 'success' ? 0 : 1, $result['errors']);
            $this->assertTrue(str_starts_with($probe->allocatedPath, $root . '/tinycore-test-'));
            $this->assertFalse(file_exists($probe->allocatedPath));
            $this->assertSame('preserved', file_get_contents($root . '/keep/sentinel'));
            $this->assertSame('*', file_get_contents($root . '/.gitignore'));
            $this->assertSame($this->app, \Spark\Foundation\Application::$app);
            $this->assertSame($environment, $_ENV);
        }

        $this->assertCount(4, array_unique($paths));
        $this->assertSame(['.gitignore', 'keep'], array_values(array_diff(scandir($root), ['.', '..'])));
    }
}
