<?php

/*
 * This file is part of CarePlan Manager by CircleLink Health.
 */

namespace AshAllenDesign\ShortURL\Classes;

use AshAllenDesign\ShortURL\Exceptions\ShortURLException;
use AshAllenDesign\ShortURL\Exceptions\ValidationException;
use AshAllenDesign\ShortURL\Models\ShortURL;
use Carbon\Carbon;
use Illuminate\Support\Str;

class Builder
{
    /**
     * The date and time that the short URL should become
     * active so that it can be visited.
     *
     * @var Carbon|null
     */
    private $activateAt;

    /**
     * The date and time that the short URL should be
     * deactivated so that it cannot be visited.
     *
     * @var Carbon|null
     */
    private $deactivateAt;

    /**
     * The destination URL that the short URL will
     * redirect to.
     *
     * @var string|null
     */
    private $destinationUrl;
    /**
     * The class that is used for generating the
     * random URL keys.
     *
     * @var KeyGenerator
     */
    private $keyGenerator;

    /**
     * The HTTP status code that will be used when
     * redirecting the user.
     *
     * @var int
     */
    private $redirectStatusCode = 301;

    /**
     * Whether or not to force the destination URL
     * and the shortened URL to use HTTPS rather
     * than HTTP.
     *
     * @var bool|null
     */
    private $secure;

    /**
     * Whether or not if the shortened URL can be
     * accessed more than once.
     *
     * @var bool
     */
    private $singleUse = false;

    /**
     * Whether or not the visitor's browser should
     * be recorded.
     *
     * @var bool|null
     */
    private $trackBrowser;

    /**
     * Whether or not the visitor's browser version
     * should be recorded.
     *
     * @var bool|null
     */
    private $trackBrowserVersion;

    /**
     * Whether or not the visitor's device type should
     * be recorded.
     *
     * @var bool|null
     */
    private $trackDeviceType;

    /**
     * Whether or not the visitor's IP address should
     * be recorded.
     *
     * @var bool|null
     */
    private $trackIPAddress;

    /**
     * Whether or not the visitor's operating system
     * should be recorded.
     *
     * @var bool|null
     */
    private $trackOperatingSystem;

    /**
     * Whether or not the visitor's operating system
     * version should be recorded.
     *
     * @var bool|null
     */
    private $trackOperatingSystemVersion;

    /**
     * Whether or not the visitor's referer URL should
     * be recorded.
     *
     * @var bool|null
     */
    private $trackRefererURL;

    /**
     * Whether or not if the short URL should track
     * statistics about the visitors.
     *
     * @var bool|null
     */
    private $trackVisits;

    /**
     * This can hold a custom URL key that might be
     * explicitly set for this URL.
     *
     * @var string|null
     */
    private $urlKey;

    /**
     * Builder constructor.
     *
     * When constructing this class, ensure that the
     * config variables are validated.
     *
     * @param  Validation          $validation
     * @throws ValidationException
     */
    public function __construct(Validation $validation = null, KeyGenerator $keyGenerator = null)
    {
        if ( ! $validation) {
            $validation = new Validation();
        }

        $this->keyGenerator = $keyGenerator ?? new KeyGenerator();

        $validation->validateConfig();
    }

    /**
     * Set the date and time that the short URL should
     * be activated and allowed to visit.
     *
     * @throws ShortURLException
     * @return $this
     */
    public function activateAt(Carbon $activationTime): self
    {
        if ($activationTime->isPast()) {
            throw new ShortURLException('The activation date must not be in the past.');
        }

        $this->activateAt = $activationTime;

        return $this;
    }

    /**
     * Set the date and time that the short URL should
     * be deactivated and not allowed to visit.
     *
     * @throws ShortURLException
     * @return $this
     */
    public function deactivateAt(Carbon $deactivationTime): self
    {
        if ($deactivationTime->isPast()) {
            throw new ShortURLException('The deactivation date must not be in the past.');
        }

        if ($this->activateAt && $deactivationTime->isBefore($this->activateAt)) {
            throw new ShortURLException('The deactivation date must not be before the activation date.');
        }

        $this->deactivateAt = $deactivationTime;

        return $this;
    }

    /**
     * Set the destination URL that the shortened URL
     * will redirect to.
     *
     * @throws ShortURLException
     * @return Builder
     */
    public function destinationUrl(string $url): self
    {
        if ( ! Str::startsWith($url, ['http://', 'https://'])) {
            throw new ShortURLException('The destination URL must begin with http:// or https://');
        }

        $this->destinationUrl = $url;

        return $this;
    }

    /**
     * Attempt to build a shortened URL and return it.
     *
     * @throws ShortURLException
     */
    public function make(): ShortURL
    {
        if ( ! $this->destinationUrl) {
            throw new ShortURLException('No destination URL has been set.');
        }

        $this->setOptions();

        do {
            if (ShortURL::where('url_key', $this->urlKey)->exists()) {
                $this->urlKey = $this->keyGenerator->generateRandom();
            }

            try {
                $shortURL = $this->insertShortURLIntoDatabase();
            } catch (\Illuminate\Database\QueryException $e) {
                if (1062 !== $e->errorInfo[1] ?? null) {
                    throw $e;
                }
                $this->urlKey = $this->keyGenerator->generateRandom();
            }
        } while ( ! isset($shortURL));

        $this->resetOptions();

        return $shortURL;
    }

    /**
     * Override the HTTP status code that will be used
     * for redirecting the visitor.
     *
     * @throws ShortURLException
     * @return $this
     */
    public function redirectStatusCode(int $statusCode): self
    {
        if ($statusCode < 300 || $statusCode > 399) {
            throw new ShortURLException('The redirect status code must be a valid redirect HTTP status code.');
        }

        $this->redirectStatusCode = $statusCode;

        return $this;
    }

    /**
     * Reset the options for the class. This is useful
     * for stopping options carrying over into
     * different short URLs that are being
     * created with the same instance of
     * this class.
     *
     * @return $this
     */
    public function resetOptions(): self
    {
        $this->urlKey             = null;
        $this->singleUse          = false;
        $this->secure             = null;
        $this->redirectStatusCode = 301;

        $this->trackVisits          = null;
        $this->trackIPAddress       = null;
        $this->trackOperatingSystem = null;
        $this->trackOperatingSystem = null;
        $this->trackBrowser         = null;
        $this->trackBrowserVersion  = null;
        $this->trackRefererURL      = null;
        $this->trackDeviceType      = null;

        return $this;
    }

    /**
     * Set whether if the destination URL and shortened
     * URL should be forced to use HTTPS.
     *
     * @return Builder
     */
    public function secure(bool $isSecure = true): self
    {
        $this->secure = $isSecure;

        return $this;
    }

    /**
     * Set whether if the shortened URL can be accessed
     * more than once.
     *
     * @return Builder
     */
    public function singleUse(bool $isSingleUse = true): self
    {
        $this->singleUse = $isSingleUse;

        return $this;
    }

    /**
     * Set whether if the short URL should track the
     * browser of the visitor.
     *
     * @return $this
     */
    public function trackBrowser(bool $track = true): self
    {
        $this->trackBrowser = $track;

        return $this;
    }

    /**
     * Set whether if the short URL should track the
     * browser version of the visitor.
     *
     * @return $this
     */
    public function trackBrowserVersion(bool $track = true): self
    {
        $this->trackBrowserVersion = $track;

        return $this;
    }

    /**
     * Set whether if the short URL should track the
     * device type of the visitor.
     *
     * @return $this
     */
    public function trackDeviceType(bool $track = true): self
    {
        $this->trackDeviceType = $track;

        return $this;
    }

    /**
     * Set whether if the short URL should track the
     * IP address of the visitor.
     *
     * @return $this
     */
    public function trackIPAddress(bool $track = true): self
    {
        $this->trackIPAddress = $track;

        return $this;
    }

    /**
     * Set whether if the short URL should track the
     * operating system of the visitor.
     *
     * @return $this
     */
    public function trackOperatingSystem(bool $track = true): self
    {
        $this->trackOperatingSystem = $track;

        return $this;
    }

    /**
     * Set whether if the short URL should track the
     * operating system version of the visitor.
     *
     * @return $this
     */
    public function trackOperatingSystemVersion(bool $track = true): self
    {
        $this->trackOperatingSystemVersion = $track;

        return $this;
    }

    /**
     * Set whether if the short URL should track the
     * referer URL of the visitor.
     *
     * @return $this
     */
    public function trackRefererURL(bool $track = true): self
    {
        $this->trackRefererURL = $track;

        return $this;
    }

    /**
     * Set whether if the short URL should track some
     * statistics of the visitors.
     *
     * @return $this
     */
    public function trackVisits(bool $trackUrlVisits = true): self
    {
        $this->trackVisits = $trackUrlVisits;

        return $this;
    }

    /**
     * Explicitly set a URL key for this short URL.
     *
     * @return $this
     */
    public function urlKey(string $key): self
    {
        $this->urlKey = urlencode($key);

        return $this;
    }

    /**
     * Store the short URL in the database.
     */
    protected function insertShortURLIntoDatabase(): ShortURL
    {
        return ShortURL::create([
            'destination_url'                => $this->destinationUrl,
            'default_short_url'              => rtrim(config('short-url.app_url'), '/').'/'.ltrim(collect(explode(config('app.url'), $this->urlKey))->filter()->first(), '/'),
            'url_key'                        => $this->urlKey,
            'single_use'                     => $this->singleUse,
            'track_visits'                   => $this->trackVisits,
            'redirect_status_code'           => $this->redirectStatusCode,
            'track_ip_address'               => $this->trackIPAddress,
            'track_operating_system'         => $this->trackOperatingSystem,
            'track_operating_system_version' => $this->trackOperatingSystemVersion,
            'track_browser'                  => $this->trackBrowser,
            'track_browser_version'          => $this->trackBrowserVersion,
            'track_referer_url'              => $this->trackRefererURL,
            'track_device_type'              => $this->trackDeviceType,
            'activated_at'                   => $this->activateAt,
            'deactivated_at'                 => $this->deactivateAt,
        ]);
    }

    /**
     * Set the options for the short URL that is being
     * created.
     */
    private function setOptions(): void
    {
        if (null === $this->secure) {
            $this->secure = config('short-url.enforce_https');
        }

        if ($this->secure) {
            $this->destinationUrl = str_replace('http://', 'https://', $this->destinationUrl);
        }

        if ( ! $this->urlKey) {
            $this->urlKey = $this->keyGenerator->generateRandom();
        }

        if ( ! $this->activateAt) {
            $this->activateAt = now();
        }

        $this->setTrackingOptions();
    }

    /**
     * Set the tracking-specific options for the short
     * URL that is being created.
     */
    private function setTrackingOptions(): void
    {
        if (null === $this->trackVisits) {
            $this->trackVisits = config('short-url.tracking.default_enabled');
        }

        if (null === $this->trackIPAddress) {
            $this->trackIPAddress = config('short-url.tracking.fields.ip_address');
        }

        if (null === $this->trackOperatingSystem) {
            $this->trackOperatingSystem = config('short-url.tracking.fields.operating_system');
        }

        if (null === $this->trackOperatingSystemVersion) {
            $this->trackOperatingSystemVersion = config('short-url.tracking.fields.operating_system_version');
        }

        if (null === $this->trackBrowser) {
            $this->trackBrowser = config('short-url.tracking.fields.browser');
        }

        if (null === $this->trackBrowserVersion) {
            $this->trackBrowserVersion = config('short-url.tracking.fields.browser_version');
        }

        if (null === $this->trackRefererURL) {
            $this->trackRefererURL = config('short-url.tracking.fields.referer_url');
        }

        if (null === $this->trackDeviceType) {
            $this->trackDeviceType = config('short-url.tracking.fields.device_type');
        }
    }
}
