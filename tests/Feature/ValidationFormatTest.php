<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

final class ValidationFormatTest extends FrameworkTestCase
{
    private function checkRule(string $rule, mixed $value, bool $valid): void
    {
        $validator = \Spark\Http\Validator::make();
        $result = $validator->validate(['value' => $rule], ['value' => $value, 'unvalidated' => 'excluded']);
        $this->assertSame($valid, $result !== false);
        $this->assertSame(!$valid, $validator->fails());
        if ($valid) {
            $this->assertSame(['value' => $value], $result->toArray());
            $this->assertSame([], $validator->errors());
        } else {
            $this->assertArrayHasKey('value', $validator->errors());
            $this->assertNotNull($validator->getFirstError());
        }
    }

    public function test_email_plus_address(): void
    {
        $this->checkRule('email', 'ada+work@example.test', true);
    }

    public function test_email_missing_domain(): void
    {
        $this->checkRule('email', 'ada@', false);
    }

    public function test_email_spaces(): void
    {
        $this->checkRule('email', 'ada smith@example.test', false);
    }

    public function test_email_array(): void
    {
        $this->checkRule('email', ['ada@example.test'], false);
    }

    public function test_url_https(): void
    {
        $this->checkRule('url', 'https://example.test/path?q=1', true);
    }

    public function test_url_relative(): void
    {
        $this->checkRule('url', '/path', false);
    }

    public function test_url_missing_host(): void
    {
        $this->checkRule('url', 'https://', false);
    }

    public function test_url_array(): void
    {
        $this->checkRule('url', ['https://example.test'], false);
    }

    public function test_ip_v4(): void
    {
        $this->checkRule('ip', '127.0.0.1', true);
    }

    public function test_ip_v6(): void
    {
        $this->checkRule('ip', '::1', true);
    }

    public function test_ip_overflow(): void
    {
        $this->checkRule('ip', '256.1.1.1', false);
    }

    public function test_ip_cidr(): void
    {
        $this->checkRule('ip', '10.0.0.1/24', false);
    }

    public function test_ipv4_valid(): void
    {
        $this->checkRule('ipv4', '192.168.1.1', true);
    }

    public function test_ipv4_v6(): void
    {
        $this->checkRule('ipv4', '::1', false);
    }

    public function test_ipv4_leading_zero(): void
    {
        $this->checkRule('ipv4', '01.2.3.4', false);
    }

    public function test_ipv6_compressed(): void
    {
        $this->checkRule('ipv6', '2001:db8::1', true);
    }

    public function test_ipv6_v4(): void
    {
        $this->checkRule('ipv6', '127.0.0.1', false);
    }

    public function test_ipv6_bad_hex(): void
    {
        $this->checkRule('ipv6', '2001:xyz::1', false);
    }

    public function test_mac_address_colon(): void
    {
        $this->checkRule('mac_address', '00:1A:2B:3C:4D:5E', true);
    }

    public function test_mac_address_too_short(): void
    {
        $this->checkRule('mac_address', '00:1A:2B', false);
    }

    public function test_mac_address_invalid_hex(): void
    {
        $this->checkRule('mac_address', 'GG:1A:2B:3C:4D:5E', false);
    }

    public function test_uuid_v4(): void
    {
        $this->checkRule('uuid', '550e8400-e29b-41d4-a716-446655440000', true);
    }

    public function test_uuid_uppercase(): void
    {
        $this->checkRule('uuid', '550E8400-E29B-41D4-A716-446655440000', true);
    }

    public function test_uuid_bad_variant(): void
    {
        $this->checkRule('uuid', '550e8400-e29b-41d4-0716-446655440000', false);
    }

    public function test_uuid_missing_hyphens(): void
    {
        $this->checkRule('uuid', '550e8400e29b41d4a716446655440000', false);
    }

    public function test_alpha_unicode(): void
    {
        $this->checkRule('alpha', 'বাংলা', true);
    }

    public function test_alpha_digits(): void
    {
        $this->checkRule('alpha', 'abc123', false);
    }

    public function test_alpha_spaces(): void
    {
        $this->checkRule('alpha', 'abc def', false);
    }

    public function test_alpha_array(): void
    {
        $this->checkRule('alpha', ['abc'], false);
    }

    public function test_alpha_ascii_ascii(): void
    {
        $this->checkRule('alpha:ascii', 'Abc', true);
    }

    public function test_alpha_ascii_unicode(): void
    {
        $this->checkRule('alpha:ascii', 'é', false);
    }

    public function test_alpha_num_unicode_digits(): void
    {
        $this->checkRule('alpha_num', 'বাংলা123', true);
    }

    public function test_alpha_num_hyphen(): void
    {
        $this->checkRule('alpha_num', 'a-b', false);
    }

    public function test_alpha_num_underscore(): void
    {
        $this->checkRule('alpha_num', 'a_b', false);
    }

    public function test_alpha_dash_dash_underscore(): void
    {
        $this->checkRule('alpha_dash', 'a-b_c1', true);
    }

    public function test_alpha_dash_space(): void
    {
        $this->checkRule('alpha_dash', 'a b', false);
    }

    public function test_alpha_dash_punctuation(): void
    {
        $this->checkRule('alpha_dash', 'a.b', false);
    }

    public function test_lowercase_lower(): void
    {
        $this->checkRule('lowercase', 'hello', true);
    }

    public function test_lowercase_mixed(): void
    {
        $this->checkRule('lowercase', 'Hello', false);
    }

    public function test_lowercase_number(): void
    {
        $this->checkRule('lowercase', 7, false);
    }

    public function test_uppercase_upper(): void
    {
        $this->checkRule('uppercase', 'HELLO', true);
    }

    public function test_uppercase_mixed(): void
    {
        $this->checkRule('uppercase', 'Hello', false);
    }

    public function test_uppercase_number(): void
    {
        $this->checkRule('uppercase', 7, false);
    }

    public function test_starts_with_PRE_match(): void
    {
        $this->checkRule('starts_with:PRE', 'PRE-value', true);
    }

    public function test_starts_with_PRE_wrong_position(): void
    {
        $this->checkRule('starts_with:PRE', 'valuePRE', false);
    }

    public function test_starts_with_PRE_case_sensitive(): void
    {
        $this->checkRule('starts_with:PRE', 'pre-value', false);
    }

    public function test_ends_with_END_match(): void
    {
        $this->checkRule('ends_with:END', 'valueEND', true);
    }

    public function test_ends_with_END_wrong_position(): void
    {
        $this->checkRule('ends_with:END', 'ENDvalue', false);
    }

    public function test_ends_with_END_case_sensitive(): void
    {
        $this->checkRule('ends_with:END', 'valueend', false);
    }

    public function test_contains_mid_match(): void
    {
        $this->checkRule('contains:mid', 'amidb', true);
    }

    public function test_contains_mid_absent(): void
    {
        $this->checkRule('contains:mid', 'value', false);
    }

    public function test_contains_mid_array(): void
    {
        $this->checkRule('contains:mid', ['mid'], false);
    }

    public function test_not_contains_bad_clean(): void
    {
        $this->checkRule('not_contains:bad', 'good', true);
    }

    public function test_not_contains_bad_blocked(): void
    {
        $this->checkRule('not_contains:bad', 'bad-value', false);
    }

    public function test_not_contains_bad_array(): void
    {
        $this->checkRule('not_contains:bad', ['good'], false);
    }

    public function test_digits_3_leading_zeros(): void
    {
        $this->checkRule('digits:3', '007', true);
    }

    public function test_digits_3_integer(): void
    {
        $this->checkRule('digits:3', 123, true);
    }

    public function test_digits_3_short(): void
    {
        $this->checkRule('digits:3', '12', false);
    }

    public function test_digits_3_long(): void
    {
        $this->checkRule('digits:3', '1234', false);
    }

    public function test_digits_3_negative(): void
    {
        $this->checkRule('digits:3', '-12', false);
    }

    public function test_digits_3_decimal(): void
    {
        $this->checkRule('digits:3', '1.2', false);
    }

    public function test_digits_between_2_4_lower(): void
    {
        $this->checkRule('digits_between:2,4', '12', true);
    }

    public function test_digits_between_2_4_upper(): void
    {
        $this->checkRule('digits_between:2,4', '1234', true);
    }

    public function test_digits_between_2_4_below(): void
    {
        $this->checkRule('digits_between:2,4', '1', false);
    }

    public function test_digits_between_2_4_above(): void
    {
        $this->checkRule('digits_between:2,4', '12345', false);
    }

    public function test_min_digits_3_boundary(): void
    {
        $this->checkRule('min_digits:3', '123', true);
    }

    public function test_min_digits_3_below(): void
    {
        $this->checkRule('min_digits:3', '12', false);
    }

    public function test_max_digits_3_boundary(): void
    {
        $this->checkRule('max_digits:3', '123', true);
    }

    public function test_max_digits_3_above(): void
    {
        $this->checkRule('max_digits:3', '1234', false);
    }

    public function test_min_value_5_boundary(): void
    {
        $this->checkRule('min_value:5', 5, true);
    }

    public function test_min_value_5_below(): void
    {
        $this->checkRule('min_value:5', 4.9, false);
    }

    public function test_min_value_5_nonnumeric(): void
    {
        $this->checkRule('min_value:5', 'five', false);
    }

    public function test_max_value_5_boundary(): void
    {
        $this->checkRule('max_value:5', 5, true);
    }

    public function test_max_value_5_above(): void
    {
        $this->checkRule('max_value:5', 5.1, false);
    }

    public function test_max_value_5_nonnumeric(): void
    {
        $this->checkRule('max_value:5', 'five', false);
    }

    public function test_date_format_Y_m_d_leap_day(): void
    {
        $this->checkRule('date_format:Y-m-d', '2024-02-29', true);
    }

    public function test_date_format_Y_m_d_nonleap(): void
    {
        $this->checkRule('date_format:Y-m-d', '2025-02-29', false);
    }

    public function test_date_format_Y_m_d_wrong_separator(): void
    {
        $this->checkRule('date_format:Y-m-d', '2024/02/29', false);
    }

    public function test_date_format_Y_m_d_trailing_data(): void
    {
        $this->checkRule('date_format:Y-m-d', '2024-02-29 extra', false);
    }

    public function test_in_red_blue_allowed(): void
    {
        $this->checkRule('in:red,blue', 'red', true);
    }

    public function test_in_red_blue_unknown(): void
    {
        $this->checkRule('in:red,blue', 'green', false);
    }

    public function test_in_red_blue_case_sensitive(): void
    {
        $this->checkRule('in:red,blue', 'RED', false);
    }

    public function test_not_in_red_blue_allowed(): void
    {
        $this->checkRule('not_in:red,blue', 'green', true);
    }

    public function test_not_in_red_blue_blocked(): void
    {
        $this->checkRule('not_in:red,blue', 'blue', false);
    }

    public function test_accepted_yes(): void
    {
        $this->checkRule('accepted', 'yes', true);
    }

    public function test_accepted_on(): void
    {
        $this->checkRule('accepted', 'on', true);
    }

    public function test_accepted_true(): void
    {
        $this->checkRule('accepted', true, true);
    }

    public function test_accepted_one(): void
    {
        $this->checkRule('accepted', 1, true);
    }

    public function test_accepted_false(): void
    {
        $this->checkRule('accepted', false, false);
    }

    public function test_accepted_zero(): void
    {
        $this->checkRule('accepted', 0, false);
    }

    public function test_accepted_no(): void
    {
        $this->checkRule('accepted', 'no', false);
    }

    public function test_declined_no(): void
    {
        $this->checkRule('declined', 'no', true);
    }

    public function test_declined_off(): void
    {
        $this->checkRule('declined', 'off', true);
    }

    public function test_declined_false(): void
    {
        $this->checkRule('declined', false, true);
    }

    public function test_declined_zero(): void
    {
        $this->checkRule('declined', 0, true);
    }

    public function test_declined_yes(): void
    {
        $this->checkRule('declined', 'yes', false);
    }

    public function test_declined_true(): void
    {
        $this->checkRule('declined', true, false);
    }

    public function test_distinct_unique(): void
    {
        $this->checkRule('distinct', [1, 2, 3], true);
    }

    public function test_distinct_duplicate(): void
    {
        $this->checkRule('distinct', [1, 2, 1], false);
    }

    public function test_distinct_empty(): void
    {
        $this->checkRule('distinct', [], true);
    }

}
