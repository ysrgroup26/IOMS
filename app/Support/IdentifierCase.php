<?php

namespace App\Support;

/**
 * v2.130.0 -- AN IDENTIFIER IS STORED IN THE CASE IT IS READ IN.
 *
 * A badge number, an operating unit's code, a shift letter: these are
 * printed on documents, matched on import and read back by people, and the
 * convention throughout IOMS is upper case. The forms now transform them as
 * they are typed (see `Components/ui/input.jsx`), and this is the other half
 * -- because a form is not the only way a value arrives. An import, an API
 * client, a browser with JavaScript disabled and a paste into a field that
 * was never given the flag all reach the same columns.
 *
 * WITHOUT BOTH HALVES THE FIX IS COSMETIC. A code stored as `gaj` by one
 * path and `GAJ` by another is two codes to a unique index and one code to
 * the person who typed them, which is how a duplicate that "already exists"
 * gets created.
 *
 * DELIBERATELY NOT A GLOBAL RULE. It is called per field, at the sites that
 * validate those fields, and never for a name, an email address, a password,
 * an address, free text, a machine key (`used_oil`) or a value whose case
 * carries meaning (the regulatory waste code `B105d` is spelled that way by
 * law). Case is the person's to choose unless a business rule says
 * otherwise.
 */
class IdentifierCase
{
    /**
     * Upper-case an identifier, leaving a null or blank value untouched.
     *
     * Null in, null out -- an optional code that was left empty must stay
     * empty rather than becoming an empty string that a unique index then
     * treats as a value.
     */
    public static function upper(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? $value : mb_strtoupper($trimmed, 'UTF-8');
    }

    /**
     * The same, applied to named keys of an array of input.
     *
     * For a controller that validates inline: `$data = IdentifierCase::in($data, ['code'])`.
     * Keys that are absent are left absent, so a partial update does not
     * acquire a field it did not send.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function in(array $input, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                $input[$key] = self::upper($input[$key]);
            }
        }

        return $input;
    }
}
