<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Translator;

final class TranslatorTest extends FrameworkTestCase
{
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
            'greeting' => 'Bonjour :name', 'Monday' => 'Lundi',
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
            'name' => 'ada', 'name_full' => 'Ada Lovelace', 'other' => ':name',
        ]));
        $t->addLines(['repeat' => ':a :a :b']);
        $this->assertSame('one one two', $t->translate('repeat', ['one', 'two']));
    }

    public function test_plural_counts_intervals_and_legacy_arrays(): void
    {
        $t = $this->translator();
        $this->assertSame('None', $t->choice('apples', 0));
        $this->assertSame('One for Ada', $t->choice('apples', 1, ['name' => 'Ada']));
        $this->assertSame('2 for Ada', $t->choice('apples', 2, ['name' => 'Ada']));
        $t->addLines([
            'indexed' => ['%d apple', '%d apples'],
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
        $this->assertSame('1 apple', $t->translate('named', 1));
        $this->assertSame('2 apples', $t->translate('indexed', 2));
        $this->assertSame('Count 2', $t->translate('Count %d', 2));
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
        $this->assertSame(count($t->all()), count($t));
    }

    public function test_invalid_locales_and_catalogues_fail_clearly(): void
    {
        $t = $this->translator();
        foreach (['../en', '/en', 'en.php', 'en/US'] as $locale) {
            $this->assertThrows(InvalidArgumentException::class, fn () => $t->setLocale($locale));
            $this->assertThrows(InvalidArgumentException::class, fn () => $t->get('greeting', locale: $locale));
        }
        file_put_contents(locale_dir('bad.php'), '<?php return 42;');
        $t->addLanguageFile(locale_dir('bad.php'));
        $this->assertThrows(UnexpectedValueException::class, fn () => $t->all());
    }

    public function test_helpers_and_array_access_share_the_application_translator(): void
    {
        $t = $this->translator();
        $this->app->instance(Translator::class, $t);
        $this->assertSame($t, trans());
        $this->assertSame($t, app(\Spark\Contracts\TranslatorContract::class));
        $this->assertSame('Bonjour Ada', trans('greeting', ['name' => 'Ada'], 'fr'));
        $this->assertSame('2 for Ada', trans_choice('apples', 2, ['name' => 'Ada']));
        $this->assertSame('Hello &lt;Ada&gt;', _e('greeting', ['name' => '<Ada>']));
        $t['custom'] = 'Custom';
        $this->assertSame('Custom', $t['custom']);
        unset($t['custom']);
        $this->assertFalse(isset($t['custom']));
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
}
