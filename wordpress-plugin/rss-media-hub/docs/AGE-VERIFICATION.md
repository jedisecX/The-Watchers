# Age verification

## What is built in

The **self-declaration** verifier shows an "I am 18 or older" button and stores a signed, expiring cookie (an HMAC over the verifier id, expiry and minimum age).

It is a **content warning, not age verification.** Several jurisdictions require reliable age assurance for sites that publish pornographic content. Examples include:

* the UK (Online Safety Act / Ofcom guidance);
* France (SREN law / Arcom standard);
* Germany (JMStV / KJM);
* a growing number of US states (for example Louisiana, Utah, Texas, Virginia, Mississippi);
* Australia (eSafety codes).

These laws change. Get legal advice for every market you serve. The plugin cannot decide compliance for you, and it shows a warning in the admin while self-declaration is active.

## Modes

| Mode | Behaviour | Caching |
|---|---|---|
| Server | Content (including media URLs) is never sent until the visitor has passed verification. The gate is served with `noindex`. | Gated pages send `private, no-store` and set `DONOTCACHEPAGE`. |
| Overlay | The page renders normally, and a JavaScript overlay blocks and blurs it until the visitor confirms. | Fully cacheable. **Deterrent only.** |

The **scope** is either posts rated *adult*, or all media pages. Logged-in staff (`rmh_moderate`) bypass the gate.

## Integrating a provider

Implement `RMH\Compliance\AgeVerifierInterface` and register it:

```php
add_action( 'rmh_loaded', function () {
    add_filter( 'rmh_age_verifiers', function ( $verifiers, $gate ) {
        $verifiers['acme'] = new My\AcmeVerifier( $gate );
        return $verifiers;
    }, 10, 2 );
} );

class AcmeVerifier implements \RMH\Compliance\AgeVerifierInterface {
    public function __construct( private \RMH\Compliance\AgeGate $gate ) {}
    public function id() { return 'acme'; }
    public function label() { return 'ACME Age Assurance'; }
    public function is_legal_verification() { return true; }
    public function is_verified() { return $this->gate->has_valid_proof( $this->id() ); }

    // Redirect the visitor to the provider (store a nonce/state server-side).
    public function render_challenge( $return_url ) {
        $state = wp_generate_password( 32, false );
        set_transient( 'acme_state_' . $state, $return_url, 15 * MINUTE_IN_SECONDS );
        $url = add_query_arg( array(
            'client_id' => ACME_CLIENT_ID,
            'state'     => $state,
            'redirect'  => admin_url( 'admin-post.php?action=rmh_age_verify' ),
        ), 'https://verify.acme.example/start' );
        return '<a class="rmh-button" href="' . esc_url( $url ) . '">Verify your age with ACME</a>';
    }

    // Provider callback lands on admin-post.php?action=rmh_age_verify.
    // Verify the signed result server-to-server; never trust query parameters alone.
    public function handle( array $request ) {
        $state = sanitize_text_field( $request['state'] ?? '' );
        if ( ! get_transient( 'acme_state_' . $state ) ) {
            return new WP_Error( 'acme_state', 'Invalid state' );
        }
        $ok = My\AcmeClient::verify_token( $request['token'] ?? '' ); // signature + age_over_18 claim
        if ( ! $ok ) {
            return new WP_Error( 'acme_failed', 'Verification failed' );
        }
        $this->gate->set_proof( $this->id() );
        return true;
    }
}
```

Then select the verifier under **Settings → Access and compliance → Age verification method**.

Notes:

* The callback handler (`admin-post.php?action=rmh_age_verify`) requires the `_rmh_age_nonce` field when the form is posted by the built-in gate.
* Provider redirects are GET requests without that nonce. Register your own `admin_post_nopriv_*` action for the callback, and call `$gate->set_proof()` after server-side validation.
* Store no personal data. The proof cookie contains only the verifier id, expiry and signature.
* Choose providers that offer privacy-preserving age assurance (age tokens, double-blind schemes).

## Jurisdiction rules

* **Settings:**
  * `Blocked jurisdictions`: ISO 3166-1 alpha-2 codes or subdivisions, e.g. `GB, US-UT`;
  * `Trusted country header`: e.g. `CF-IPCountry`, set and overwritten by your edge.
* **Response:** blocked visitors receive HTTP **451**.
* **Subdivisions:** when only the country is known, a subdivision rule conservatively blocks the whole country. Supply precise codes through `rmh_visitor_country` (for example from a GeoIP2 database) for regional rules.
* **Typical use:** block markets where you have not implemented the legally required verification.
