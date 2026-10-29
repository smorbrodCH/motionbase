<?php

namespace App\Services;

/**
 * Wie eine interaktive Grafik eingebettet wird.
 *
 * Selbst gehostete Grafiken laufen auf einer undurchsichtigen Herkunft: Sie
 * liegen auf derselben Adresse wie die App, ohne Sandbox käme hochgeladenes
 * HTML an Cookies und localStorage der angemeldeten Person.
 *
 * Eine Seite von eifach.online hat ihre eigene Herkunft, schon weil sie von
 * einem anderen Host kommt. Dort nimmt `allow-same-origin` der App nichts weg,
 * es gibt der Seite nur zurück, was sie für ihre eigene Datenbank und ihren
 * eigenen Speicher braucht.
 */
class InteractiveEmbed
{
    public static function sameOrigin(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '/')) {
            return true;
        }

        return parse_url($url, PHP_URL_HOST) === parse_url(config('app.url'), PHP_URL_HOST);
    }

    public static function sandbox(string $url): string
    {
        return self::sameOrigin($url)
            ? 'allow-scripts'
            : 'allow-scripts allow-same-origin allow-forms allow-popups allow-downloads';
    }
}
