<?php

use Spark\Configuration;

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\DotEnv;
use Spark\Http\Input;
use Spark\Http\InputErrors;

final class UtilityBoundaryTest extends FrameworkTestCase
{
    public function test_environment_cache_publication_preserves_existing_readers(): void
    {
        $source = $this->storagePath . '/atomic.env';
        $compiled = $this->storagePath . '/atomic.php';
        $environment = new DotEnv($source, $compiled);
        file_put_contents($source, 'VALUE=old');
        $environment->compile();
        $original = file_get_contents($compiled);
        $reader = fopen($compiled, 'rb');
        try {
            file_put_contents($source, 'VALUE=new');
            $environment->compile();
            $this->assertSame($original, stream_get_contents($reader));
            $this->assertSame(['VALUE' => 'new'], require $compiled);
        } finally {
            fclose($reader);
        }
    }

    public function test_atomic_file_write_keeps_original_after_partial_write(): void
    {
        $path = $this->storagePath . '/original.txt';
        file_put_contents($path, 'original');
        $file = new class extends \Spark\Utils\File {
            public static function put(string $path, mixed $contents, int $flags = 0): int|false
            {
                return parent::put($path, substr($contents, 0, 2), $flags);
            }
        };
        $this->assertFalse($file::putAtomic($path, 'replacement'));
        $this->assertSame('original', file_get_contents($path));
        $this->assertSame([], glob($path . '.tmp.*'));
    }

    public function test_config_cache_publication_preserves_existing_readers(): void
    {
        $directory = $this->storagePath . '/atomic-config';
        mkdir($directory);
        $source = $directory . '/app.php';
        $compiled = $this->storagePath . '/atomic-config.php';
        file_put_contents($source, '<?php return ["name" => "old"];');
        Configuration::discover($directory, $compiled, '');
        $original = file_get_contents($compiled);
        $reader = fopen($compiled, 'rb');
        try {
            file_put_contents($source, '<?php return ["name" => "new"];');
            touch($source, time() + 5);
            clearstatcache();
            Configuration::discover($directory, $compiled, '');
            $this->assertSame($original, stream_get_contents($reader));
            $this->assertSame('new', (require $compiled)['config']['app']['name']);
        } finally {
            fclose($reader);
        }
    }

    public function test_empty_environment_produces_a_loadable_cache(): void
    {
        $source = $this->storagePath . '/empty.env';
        $compiled = $this->storagePath . '/empty.php';
        file_put_contents($source, "# no variables\n");
        $this->assertSame([], (new DotEnv($source, $compiled))->compile());
        $this->assertSame([], require $compiled);
    }

    public function test_inline_comments_preserve_quoted_values_and_hashes(): void
    {
        $source = $this->storagePath . '/quoted.env';
        file_put_contents($source, <<<'ENV'
TEXT="a # b" # trailing comment
NUMBER='123' # still a string
ESCAPED="a \" # b" # after escaped quote
PLAIN=123 # integer
HASH=abc#def
ENV);
        $values = (new DotEnv($source, $this->storagePath . '/quoted.php'))->compile();
        $this->assertSame(['TEXT' => 'a # b', 'NUMBER' => '123', 'ESCAPED' => 'a " # b', 'PLAIN' => 123, 'HASH' => 'abc#def'], $values);
    }

    public function test_input_errors_accept_additions_to_string_messages_and_empty_rendering(): void
    {
        $errors = app(InputErrors::class);
        $errors->setMessages(['name' => 'First']);
        $errors->addMessage('name', 'Second');
        $this->assertSame(['First', 'Second'], $errors->error('name'));
        $errors->setMessages(['name' => []]);
        $this->assertSame('', (string) $errors);
    }

    public function test_text_and_html_input_preserve_zero(): void
    {
        foreach ([0, '0'] as $zero) {
            $input = new Input(['value' => $zero]);
            $this->assertSame('0', $input->text('value'));
            $this->assertSame('0', $input->escapeHtml('value'));
        }
    }
}
