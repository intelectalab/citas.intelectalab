<?php

namespace App\Support;

class Search
{
    private const ACCENTED = 'áàäâãéèëêíìïîóòöôõúùüûñ';
    private const PLAIN = 'aaaaaeeeeiiiiooooouuuun';

    public static function contains($query, string $expression, string $term, string $boolean = 'and')
    {
        return $query->whereRaw(
            "translate(lower({$expression}), '" . self::ACCENTED . "', '" . self::PLAIN . "') like ?",
            ['%' . self::normalize($term) . '%'],
            $boolean,
        );
    }

    public static function orContains($query, string $expression, string $term)
    {
        return self::contains($query, $expression, $term, 'or');
    }

    public static function normalize(string $term): string
    {
        $term = mb_strtolower(trim($term));
        $term = str_replace(mb_str_split(self::ACCENTED), str_split(self::PLAIN), $term);

        return addcslashes($term, '%_\\');
    }
}
