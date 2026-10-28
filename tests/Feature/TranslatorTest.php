<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Translator\Translator;

final class TranslatorTest extends FrameworkTestCase
{
    public function test_canonical_class_and_contract_share_one_service(): void
    {
        $t = app(Translator::class);
        $this->assertSame(Translator::class, $t::class);
        $this->assertSame($t, app('translator'));
        $this->assertSame($t, app(\Spark\Translator\Contracts\TranslatorContract::class));
        $this->assertSame($t, app()->call(fn(Translator $translator) => $translator));
        $this->assertSame($t, trans());
    }

    private function translator(): Translator
    {
        config(['app.locale_dir' => $this->storagePath . '/languages', 'app.locale' => 'en', 'app.fallback_locale' => 'en']);
        mkdir(locale_dir(), 0700, true);
        file_put_contents(locale_dir('en.php'), '<?php return ' . var_export([
            'greeting' => 'Hello :name',
            'account' => ['title' => 'Account', 'empty' => ''],
            'account.title' => 'Exact key',
            'fallback' => 'English fallback',
            'self' => 'self',
            'Monday' => 'Monday',
            'apples' => '{0} None|{1} One for :name|[2,*] :count for :name',
        ], true) . ';');
        file_put_contents(locale_dir('fr.php'), '<?php return ' . var_export([
            'greeting' => 'Bonjour :name',
            'Monday' => 'Lundi',
        ], true) . ';');
        return new Translator();
    }

    public function test_locale_switching_fallback_and_explicit_overrides(): void
    {
        $t = $this->translator();
        $this->assertSame('Hello Ada', $t->get('greeting', ['name' => 'Ada']));
        $this->assertSame('Bonjour Ada', $t->get('greeting', ['name' => 'Ada'], 'fr'));
        $this->assertSame('en', $t->getLocale());
        $t->setLocale('fr');
        $this->assertSame('English fallback', $t->get('fallback'));
        $this->assertTrue($t->has('fallback'));
        $this->assertFalse($t->has('fallback', fallback: false));
        $t->setFallback(null);
        $this->assertSame('fallback', $t->get('fallback'));
        $this->assertNull($t->getFallback());
        $this->assertFalse($t->has('fallback'));
    }

    public function test_nested_exact_empty_and_missing_keys(): void
    {
        $t = $this->translator();
        $this->assertSame('Exact key', $t->get('account.title'));
        $this->assertSame('', $t->get('account.empty'));
        $this->assertTrue($t->has('account.empty'));
        $this->assertTrue($t->has('self'));
        $this->assertSame('missing', $t->get('missing'));
        $this->assertSame(['title' => 'Account', 'empty' => ''], $t->get('account'));
    }

    public function test_replacements_are_non_recursive_and_support_case_variants(): void
    {
        $t = $this->translator();
        $t->addLines(['names' => ':name_full :name :NAME :Name :other']);
        $this->assertSame('Ada Lovelace ada ADA Ada :name', $t->get('names', [
            'name' => 'ada',
            'name_full' => 'Ada Lovelace',
            'other' => ':name',
        ]));
        $t->addLines(['repeat' => ':a :a :b']);
        $this->app->instance(Translator::class, $t);
        $this->assertSame('one one two', __('repeat', ['one', 'two']));
    }

    public function test_plural_counts_intervals_and_legacy_arrays(): void
    {
        $t = $this->translator();
        $this->assertSame('None', $t->choice('apples', 0));
        $this->assertSame('One for Ada', $t->choice('apples', 1, ['name' => 'Ada']));
        $this->assertSame('2 for Ada', $t->choice('apples', 2, ['name' => 'Ada']));
        $t->addLines([
            'indexed' => [':count apple', ':count apples'],
            'legacy' => ['%d apple', '%d apples'],
            'named' => ['one' => ':count apple', 'other' => ':count apples'],
            'pipe' => ':count apple|:count apples',
            'fraction' => '[0,1] Small|[2,*] Large|Other',
        ]);
        foreach (['indexed', 'named', 'pipe'] as $key) {
            $this->assertSame('1 apple', $t->choice($key, 1));
            $this->assertSame('0 apples', $t->choice($key, 0));
            $this->assertSame('2 apples', $t->choice($key, 2));
        }
        $this->assertSame('Small', $t->choice('fraction', 0.5));
        $this->assertSame('Other', $t->choice('fraction', 1.5));
        $this->app->instance(Translator::class, $t);
        $this->assertSame('1 apple', __('named', 1));
        $this->assertSame('2 apples', __('legacy', 2));
        $this->assertSame('Count 2', __('Count %d', 2));
        $this->assertSame('Bonjour Ada', __('greeting', ['name' => 'Ada'], locale: 'fr'));
        $this->assertSame('Bonjour &lt;Ada&gt;', _e('greeting', ['name' => '<Ada>'], locale: 'fr'));
        $this->assertFalse(method_exists($t, 'translate'));
    }

    public function test_formatting_preserves_arguments_errors_and_scoped_locales(): void
    {
        $t = $this->translator();
        $t->addLines(['fruit' => ['one' => '%s apple', 'other' => '%s apples']]);
        $this->app->instance(Translator::class, $t);
        $name = new class implements Stringable {
            public function __toString(): string
            {
                return 'Ada';
            }
        };

        $cases = [
            ['Plain text', null, [], [], 'Plain text'],
            ['Hello :name :name', [$name], [], [], 'Hello Ada Ada'],
            ['Values :a/:b/:c', [null, false, true], [], [], 'Values /0/1'],
            ['Hello :name :missing', ['Ada'], [], [], 'Hello Ada :missing'],
            ['%s has %d apples', ['Ada', 2], [], [], 'Ada has 2 apples'],
            ['Missing %s %s', ['Ada'], [], [], 'Missing %s %s'],
            ['Invalid %q', ['Ada'], [], [], 'Invalid %q'],
            ['fruit', 1, ['one'], ['many'], 'one apple'],
            ['fruit', 2, ['one'], ['many'], 'many apples'],
            ['fruit', null, ['one'], [], 'one apple'],
        ];
        foreach ($cases as [$text, $arg, $args, $args2, $expected]) {
            $this->assertSame($expected, $t->format($text, $arg, $args, $args2));
            $this->assertSame($expected, __($text, $arg, $args, $args2));
        }

        $this->assertSame('Bonjour Ada', $t->withLocale('fr')->format('greeting', [$name]));
        $this->assertSame('Hello Ada', __('greeting', [$name]));
        $this->assertThrows(InvalidArgumentException::class, fn() => $t->format(':name', [[]]));
    }

    public function test_files_have_deterministic_precedence_and_are_cached_until_flush(): void
    {
        $t = $this->translator();
        $file = locale_dir('extra.php');
        file_put_contents($file, "<?php return ['greeting' => 'Override'];");
        $t->addLanguageFile($file);
        $this->assertSame('Override', $t->get('greeting'));
        $this->assertSame('English fallback', $t->get('fallback'));
        $this->assertSame('Override', $t->get('greeting'));
        file_put_contents($file, "<?php return ['greeting' => 'Changed'];");
        $this->assertSame('Override', $t->get('greeting'));
        $t->addLines(['greeting' => 'Runtime']);
        $this->assertSame('Runtime', $t->get('greeting'));
        $this->assertCount(2, $t->getLoadedFiles());
        $this->assertTrue($t->hasLoadedFiles());
        $t->flush();
        $this->assertFalse($t->hasLoadedFiles());
        $this->assertSame('Changed', $t->get('greeting'));
        $this->assertCount(7, $t->all());
    }

    public function test_invalid_locales_and_catalogues_fail_clearly(): void
    {
        $t = $this->translator();
        foreach (['../en', '/en', 'en.php', 'en/US'] as $locale) {
            $this->assertThrows(InvalidArgumentException::class, fn() => $t->setLocale($locale));
            $this->assertThrows(InvalidArgumentException::class, fn() => $t->get('greeting', locale: $locale));
        }
        file_put_contents(locale_dir('bad.php'), '<?php return 42;');
        $t->addLanguageFile(locale_dir('bad.php'));
        $this->assertThrows(UnexpectedValueException::class, fn() => $t->all());
    }

    public function test_helpers_share_the_application_translator(): void
    {
        $t = $this->translator();
        $this->app->instance(Translator::class, $t);
        $this->assertSame($t, trans());
        $this->assertSame($t, app(\Spark\Translator\Contracts\TranslatorContract::class));
        $this->assertSame('Bonjour Ada', trans('greeting', ['name' => 'Ada'], 'fr'));
        $this->assertSame('2 for Ada', trans_choice('apples', 2, ['name' => 'Ada']));
        $this->assertSame('Hello &lt;Ada&gt;', _e('greeting', ['name' => '<Ada>']));
        $t->addLines(['custom' => 'Custom']);
        $this->assertSame('Custom', $t->get('custom'));
        $this->assertSame('2 for Ada', trans_choice('apples', ['a', 'b'], ['name' => 'Ada']));
    }

    public function test_carbon_locale_does_not_leak_when_the_translator_switches_locale(): void
    {
        $t = $this->translator();
        $this->app->instance(Translator::class, $t);
        $t->setLocale('fr');
        $date = \Spark\Carbon::parse('2026-01-05');
        $this->assertSame('Monday', $date->locale('en')->getDayName());
        $this->assertSame('Lundi', $date->locale('fr')->getDayName());
    }

    public function test_group_files_are_lazy_and_paths_runtime_and_flat_keys_have_defined_precedence(): void
    {
        $t = $this->translator();
        mkdir(locale_dir('en'), 0700, true);
        file_put_contents(locale_dir('en/auth.php'), "<?php return ['failed' => 'Default', 'nested' => ['label' => 'Nested']];");
        $extra = $this->storagePath . '/overrides';
        mkdir($extra . '/en', 0700, true);
        file_put_contents($extra . '/en/auth.php', "<?php return ['failed' => 'Override'];");
        $t->get('greeting');
        $this->assertCount(1, $t->getLoadedFiles());
        $t->addPath($extra);
        $this->assertSame('Override', $t->get('auth.failed'));
        $this->assertSame('Nested', $t->get('auth.nested.label'));
        $t->addLines(['failed' => 'Runtime'], group: 'auth');
        $this->assertSame('Runtime', $t->get('auth.failed'));
        $t->addLines(['auth.failed' => 'Flat']);
        $this->assertSame('Flat', $t->get('auth.failed'));
        $this->assertSame('Runtime', $t->all(group: 'auth')['failed']);
    }

    public function test_runtime_updates_do_not_reload_files_until_flush(): void
    {
        $t = $this->translator();
        $this->assertSame('Hello Ada', $t->get('greeting', ['name' => 'Ada']));
        file_put_contents(locale_dir('en.php'), "<?php return ['greeting' => 'Changed'];");
        $t->addLines(['new' => 'Added']);
        $this->assertSame('Hello Ada', $t->get('greeting', ['name' => 'Ada']));
        $t->addPath($this->storagePath . '/missing');
        $this->assertSame('Hello Ada', $t->get('greeting', ['name' => 'Ada']));
        $t->flush();
        $this->assertSame('Changed', $t->get('greeting'));
        $this->assertFalse($t->has('new'));
    }

    public function test_parent_fallback_uses_the_resolved_language_for_plural_rules(): void
    {
        $t = $this->translator();
        $t->addLines(['items' => ['one' => ':count item', 'other' => ':count items']], 'en');
        $this->assertSame('21 items', $t->choice('items', 21, locale: 'ru'));
        $t->addLines(['parent' => 'Script parent'], 'zh_Hant');
        $this->assertSame('Script parent', $t->get('parent', locale: 'zh_Hant_TW'));
        $this->assertFalse($t->has('parent', 'zh_Hant_TW', false));
        $t->setFallback('fr_CA');
        $this->assertSame('Bonjour Ada', $t->get('greeting', ['name' => 'Ada'], 'de'));
        $t->setFallback(null);
        $this->assertSame('Bonjour Ada', $t->get('greeting', ['name' => 'Ada'], 'fr_CA'));
    }

    public function test_scoped_carbon_uses_custom_paths_runtime_lines_and_preserves_the_original_locale(): void
    {
        $t = $this->translator();
        $t->addLines(['Monday' => 'Runtime Monday', '%d minutes ago' => ':count minutes translated'], 'fr');
        $this->app->instance(Translator::class, $t);
        $date = \Spark\Carbon::parse('2026-01-05 12:00:00')->locale('fr');
        $this->assertSame('Runtime Monday', $date->getDayName());
        $this->assertSame('2 minutes translated', $date->subMinutes(2)->diffForHumans($date));
        $scoped = $t->withLocale('fr');
        $scoped->addLines(['greeting' => 'Scoped']);
        $this->assertSame('Scoped', $scoped->get('greeting'));
        $this->assertSame('Bonjour Ada', $t->get('greeting', ['name' => 'Ada'], 'fr'));
        $this->assertSame('en', $t->getLocale());
    }

    public function test_carbon_preserves_regional_catalogue_names(): void
    {
        $t = $this->translator();
        file_put_contents(locale_dir('fr_CA.php'), "<?php return ['Monday' => 'Regional Monday'];");
        $this->app->instance(Translator::class, $t);
        $date = \Spark\Carbon::parse('2026-01-05')->locale('fr-ca');
        $this->assertSame('fr_CA', $date->locale());
        $this->assertSame('Regional Monday', $date->getDayName());
        $this->assertSame('en', $t->getLocale());
    }

    public function test_missing_handler_is_reentrant_and_recovers_after_exceptions(): void
    {
        $t = $this->translator();
        $calls = [];
        $t->handleMissingKeysUsing(function ($key, $locale) use ($t, &$calls) {
            $calls[] = [$key, $locale];
            $t->get('nested miss');
        });
        $this->assertSame('missing', $t->get('missing', locale: 'fr'));
        $this->assertSame([['missing', 'fr']], $calls);
        $t->handleMissingKeysUsing(fn() => throw new RuntimeException('handler failed'));
        $this->assertThrows(RuntimeException::class, fn() => $t->get('missing'));
        $t->handleMissingKeysUsing(null);
        $this->assertSame('missing', $t->get('missing'));
    }

    public function test_choice_accepts_countables_and_rejects_invalid_inputs(): void
    {
        $t = $this->translator();
        $this->assertSame('2 for Ada', $t->choice('apples', new ArrayObject([1, 2]), ['name' => 'Ada', 'count' => 99]));
        $this->assertSame('2 missing', $t->choice(':count missing', 2, ['count' => 99]));
        foreach ([INF, -INF, NAN] as $number) {
            $this->assertThrows(InvalidArgumentException::class, fn() => $t->choice('apples', $number));
        }
        $this->assertThrows(InvalidArgumentException::class, fn() => $t->addLines([], group: '../outside'));
        $this->assertThrows(InvalidArgumentException::class, fn() => $t->all('../outside'));
        $this->assertThrows(InvalidArgumentException::class, fn() => $t->get('greeting', ['name' => []]));
        $t->addLines(['invalid' => ['one' => ['nested']]]);
        $this->assertThrows(UnexpectedValueException::class, fn() => $t->choice('invalid', 1));
    }

    public function test_plural_categories_cover_supported_language_boundaries(): void
    {
        $t = new Translator([]);
        $cases = [
            ['en', 1, 'one'],
            ['en', 1.5, 'other'],
            ['bn', 0, 'one'],
            ['bn', 0.5, 'one'],
            ['ja', 1, 'other'],
            ['ru', 1, 'one'],
            ['ru', 2, 'few'],
            ['ru', 5, 'many'],
            ['ru', 11, 'many'],
            ['ru', 21, 'one'],
            ['ru', 1.5, 'other'],
            ['pl', 21, 'many'],
            ['ar', 0, 'zero'],
            ['ar', 1, 'one'],
            ['ar', 2, 'two'],
            ['ar', 3, 'few'],
            ['ar', 11, 'many'],
            ['ar', 100, 'other'],
            ['ar', 1.5, 'other'],
            ['fr', 1.5, 'one'],
            ['fr', 1000000, 'many'],
            ['pt', 0, 'one'],
            ['pt-PT', 0, 'other'],
            ['pt_PT', 1.5, 'other'],
            ['pt_PT', 1000000, 'many'],
            ['en', -1, 'one'],
            ['ru', PHP_INT_MAX, 'many'],
        ];
        foreach ($cases as [$locale, $number, $category]) {
            $t->addLines(['forms' => array_combine(['zero', 'one', 'two', 'few', 'many', 'other'], ['zero', 'one', 'two', 'few', 'many', 'other'])], $locale);
            $this->assertSame($category, $t->choice('forms', $number, locale: $locale), "$locale: $number");
        }
    }
}
