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
export function isSameOrigin(url: string): boolean {
    if (!url || url.startsWith('/')) {
        return true;
    }

    try {
        return new URL(url, window.location.origin).origin === window.location.origin;
    } catch {
        return true;
    }
}

export function sandboxFor(url: string): string {
    return isSameOrigin(url)
        ? 'allow-scripts'
        : 'allow-scripts allow-same-origin allow-forms allow-popups allow-downloads';
}
