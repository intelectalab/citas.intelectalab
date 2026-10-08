<?php

namespace App\Support;

/**
 * Búsqueda de texto que no distingue mayúsculas ni tildes: "ramirez"
 * encuentra a "Ramírez". No depende de extensiones de PostgreSQL.
 */
class Search
{
    private const ACCENTED = 'áàäâãéèëêíìïîóòöôõúùüûñ';
    private const PLAIN = 'aaaaaeeeeiiiiooooouuuun';

    /**
     * Agrega a la consulta "la expresión contiene el término". La expresión es
     * SQL propio (una columna o una concatenación), nunca texto del usuario.
     */
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

        // Los comodines que escriba el usuario se buscan como texto.
        return addcslashes($term, '%_\\');
    }
}
