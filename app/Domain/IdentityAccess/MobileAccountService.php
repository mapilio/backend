<?php

namespace App\Domain\IdentityAccess;

use App\Notifications\MobileAccountActionNotification;
use App\Support\Database\LegacyDatabase;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class MobileAccountService
{
    public function __construct(private readonly AppleAccountRevoker $appleRevoker) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{success: true, message: list<string>}
     */
    public function register(array $parameters, string $verificationRoute = 'api.legacy.mobile-accounts.verify'): array
    {
        $parameters['email'] = Str::lower(trim((string) ($parameters['email'] ?? '')));
        $validated = $this->validate($parameters, [
            'name' => ['required', 'string', 'max:55'],
            'username' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'callback' => ['required', 'string', 'max:2048'],
            'success-params' => ['required', 'string', 'max:200', 'regex:/\A[A-Za-z0-9_.~-]+=[A-Za-z0-9_.~-]+\z/D'],
            'error-params' => ['required', 'string', 'max:200', 'regex:/\A[A-Za-z0-9_.~-]+=[A-Za-z0-9_.~-]+\z/D'],
            'referrer' => ['nullable', 'string', 'max:255'],
        ]);

        $connection = LegacyDatabase::connection();
        $this->assertAvailableIdentity($connection, $validated['email'], $validated['username']);
        $callback = $this->allowedCallback($validated['callback']);
        $activationCode = Str::random(64);
        $activationDigest = hash('sha256', $activationCode);

        try {
            $userId = $connection->transaction(fn (): int => (int) $connection
                ->table('default_users_users')
                ->insertGetId(array_filter([
                    'email' => $validated['email'],
                    'username' => $validated['username'],
                    'password' => Hash::make($validated['password']),
                    'display_name' => $validated['name'],
                    'first_name' => Str::before($validated['name'], ' '),
                    'activated' => false,
                    'enabled' => true,
                    'activation_code' => $activationDigest,
                    'str_id' => Str::random(24),
                    'referrer' => $validated['referrer'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], static fn (mixed $value): bool => $value !== null)));
        } catch (UniqueConstraintViolationException) {
            throw MobileAccountException::validation([
                'email' => ['The email or username has already been taken.'],
            ]);
        }

        $url = URL::temporarySignedRoute(
            $verificationRoute,
            now()->addMinutes($this->activationMinutes()),
            [
                'user' => $userId,
                'code' => $activationCode,
                'callback' => $callback,
                'success' => $validated['success-params'],
                'error' => $validated['error-params'],
            ],
        );

        try {
            $this->notify(
                $validated['email'],
                $validated['name'],
                'Activate your Mapilio account',
                'Confirm your email address to finish creating your Mapilio account.',
                'Activate account',
                $url,
            );
        } catch (Throwable $exception) {
            $connection->table('default_users_users')
                ->where('id', $userId)
                ->where('activation_code', $activationDigest)
                ->where('activated', false)
                ->delete();

            Log::error('Mobile account activation email could not be sent.', [
                'user_id' => $userId,
                'exception' => $exception::class,
            ]);

            throw new MobileAccountException('Account creation is temporarily unavailable.', 503);
        }

        return [
            'success' => true,
            'message' => ['Check your email to activate your account.'],
        ];
    }

    public function activate(int $userId, string $code): bool
    {
        return LegacyDatabase::connection()
            ->table('default_users_users')
            ->where('id', $userId)
            ->where('activation_code', hash('sha256', $code))
            ->where('activated', false)
            ->where('enabled', true)
            ->whereNull('deleted_at')
            ->update([
                'activated' => true,
                'activation_code' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{success: true}
     */
    public function requestPasswordReset(array $parameters, string $verificationRoute = 'api.legacy.mobile-password.verify'): array
    {
        $parameters['email'] = Str::lower(trim((string) ($parameters['email'] ?? '')));
        $validated = $this->validate($parameters, [
            'email' => ['required', 'email', 'max:255'],
            'callback' => ['required', 'string', 'max:2048'],
            'success-params' => ['required', 'string', 'max:200', 'regex:/\A[A-Za-z0-9_.~-]+=[A-Za-z0-9_.~-]+\z/D'],
            'error-params' => ['required', 'string', 'max:200', 'regex:/\A[A-Za-z0-9_.~-]+=[A-Za-z0-9_.~-]+\z/D'],
        ]);

        $callback = $this->allowedCallback($validated['callback']);
        $connection = LegacyDatabase::connection();
        $user = $connection->table('default_users_users')
            ->where('email', $validated['email'])
            ->whereNull('deleted_at')
            ->where('enabled', true)
            ->first();

        if ($user === null) {
            return ['success' => true];
        }

        $resetCode = Str::random(64);
        $resetDigest = hash('sha256', $resetCode);
        $connection->table('default_users_users')
            ->where('id', $user->id)
            ->update(['reset_code' => $resetDigest, 'updated_at' => now()]);

        $url = URL::temporarySignedRoute(
            $verificationRoute,
            now()->addMinutes($this->resetMinutes()),
            [
                'user' => (int) $user->id,
                'code' => $resetCode,
                'callback' => $callback,
                'success' => $validated['success-params'],
                'error' => $validated['error-params'],
            ],
        );

        try {
            $this->notify(
                $validated['email'],
                (string) ($user->display_name ?: $user->username),
                'Reset your Mapilio password',
                'Use this link to choose a new password for your Mapilio account.',
                'Reset password',
                $url,
            );
        } catch (Throwable $exception) {
            Log::error('Mobile password reset email could not be sent.', [
                'user_id' => (int) $user->id,
                'exception' => $exception::class,
            ]);
        }

        return ['success' => true];
    }

    public function passwordResetRedirect(int $userId, string $code, string $callback, string $success, string $error): string
    {
        $user = LegacyDatabase::connection()
            ->table('default_users_users')
            ->where('id', $userId)
            ->whereNull('deleted_at')
            ->where('enabled', true)
            ->first(['reset_code']);

        if ($user === null
            || ! is_string($user->reset_code)
            || ! hash_equals($user->reset_code, hash('sha256', $code))) {
            return $this->appendCallback($callback, $error);
        }

        $opaqueCode = Crypt::encryptString(json_encode([
            'user' => $userId,
            'code' => $code,
            'expires' => now()->addMinutes($this->resetFormMinutes())->timestamp,
        ], JSON_THROW_ON_ERROR));

        return $this->appendCallback($callback, $success, ['code' => $opaqueCode]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{success: true}
     */
    public function resetPassword(array $parameters): array
    {
        $validated = $this->validate($parameters, [
            'code' => ['required', 'string', 'max:4096'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            're-password' => ['required', 'same:password'],
        ]);

        try {
            $payload = json_decode(Crypt::decryptString($validated['code']), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new MobileAccountException('The password reset link is invalid or expired.');
        }

        if (! is_array($payload)
            || ! isset($payload['user'], $payload['code'], $payload['expires'])
            || (int) $payload['expires'] < time()) {
            throw new MobileAccountException('The password reset link is invalid or expired.');
        }

        $connection = LegacyDatabase::connection();
        $user = $connection
            ->table('default_users_users')
            ->where('id', (int) $payload['user'])
            ->whereNull('deleted_at')
            ->where('enabled', true)
            ->first(['reset_code']);

        if ($user === null
            || ! is_string($user->reset_code)
            || ! hash_equals($user->reset_code, hash('sha256', (string) $payload['code']))) {
            throw new MobileAccountException('The password reset link is invalid or expired.');
        }

        $updated = $connection->table('default_users_users')
            ->where('id', (int) $payload['user'])
            ->where('reset_code', $user->reset_code)
            ->whereNull('deleted_at')
            ->where('enabled', true)
            ->update([
                'password' => Hash::make($validated['password']),
                'reset_code' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new MobileAccountException('The password reset link is invalid or expired.');
        }

        return ['success' => true];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{status: true, response: array<string, mixed>}
     */
    public function updateProfile(int $userId, array $parameters, ?UploadedFile $photo): array
    {
        if ($photo !== null) {
            $parameters['user_profile_photo'] = $photo;
        }

        $validated = $this->validate($parameters, [
            'user_bio' => ['sometimes', 'nullable', 'string', 'max:200'],
            'display_name' => ['sometimes', 'required', 'string', 'max:40'],
            'username' => ['sometimes', 'required', 'string', 'max:20'],
            'hidden_profile' => ['sometimes', 'boolean'],
            'user_profile_photo' => ['sometimes', 'file', 'max:2048', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif'],
        ]);

        $connection = LegacyDatabase::connection();

        if (isset($validated['username']) && $connection->table('default_users_users')
            ->where('username', $validated['username'])
            ->where('id', '!=', $userId)
            ->exists()) {
            throw MobileAccountException::validation(['username' => ['The username has already been taken.']]);
        }

        $storedPath = null;

        if ($photo !== null) {
            $disk = $this->profilePhotoDisk();
            $storedPath = $photo->store("profile-photos/{$userId}", $disk);

            if (! is_string($storedPath)) {
                throw new MobileAccountException('The profile photo could not be stored.', 503);
            }

            $validated['user_profile_photo'] = Storage::disk($disk)->url($storedPath);
        }

        $updates = array_intersect_key($validated, array_flip([
            'user_bio',
            'display_name',
            'username',
            'hidden_profile',
            'user_profile_photo',
        ]));
        $updates['updated_by_id'] = $userId;
        $updates['updated_at'] = now();

        try {
            $updated = $connection->table('default_users_users')
                ->where('id', $userId)
                ->whereNull('deleted_at')
                ->where('enabled', true)
                ->update($updates);
        } catch (UniqueConstraintViolationException) {
            if ($storedPath !== null) {
                Storage::disk($this->profilePhotoDisk())->delete($storedPath);
            }

            throw MobileAccountException::validation(['username' => ['The username has already been taken.']]);
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk($this->profilePhotoDisk())->delete($storedPath);
            }

            throw $exception;
        }

        if ($updated !== 1) {
            if ($storedPath !== null) {
                Storage::disk($this->profilePhotoDisk())->delete($storedPath);
            }

            throw new MobileAccountException('The account is unavailable.', 409);
        }

        $user = $connection->table('default_users_users')->where('id', $userId)->first([
            'id', 'username', 'email', 'display_name', 'user_profile_photo', 'user_bio', 'hidden_profile', 'updated_at',
        ]);

        return ['status' => true, 'response' => (array) $user];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{status: true, response: true}
     */
    public function requestEmailChange(
        int $userId,
        array $parameters,
        string $verificationRoute = 'api.legacy.mobile-email.verify',
    ): array {
        $parameters['email'] = Str::lower(trim((string) ($parameters['email'] ?? '')));
        $validated = $this->validate($parameters, ['email' => ['required', 'email', 'max:255']]);
        $connection = LegacyDatabase::connection();
        $user = $connection->table('default_users_users')->where('id', $userId)->whereNull('deleted_at')->first();

        if ($user === null || ! $this->isProviderGeneratedEmail((string) $user->username, (string) $user->email)) {
            throw new MobileAccountException('This email address cannot be changed through this flow.', 409);
        }

        if ($connection->table('default_users_users')->where('email', $validated['email'])->exists()) {
            throw MobileAccountException::validation(['email' => ['The email has already been taken.']]);
        }

        $callback = $this->allowedCallback((string) config('mapilio.mobile_accounts.email_verification_callback'));
        $url = URL::temporarySignedRoute(
            $verificationRoute,
            now()->addMinutes($this->activationMinutes()),
            [
                'user' => $userId,
                'current' => hash('sha256', Str::lower((string) $user->email)),
                'email' => $validated['email'],
                'callback' => $callback,
            ],
        );

        $this->notify(
            $validated['email'],
            (string) ($user->display_name ?: $user->username),
            'Confirm your Mapilio email',
            'Confirm this address to use it with your Mapilio account.',
            'Confirm email',
            $url,
        );

        return ['status' => true, 'response' => true];
    }

    public function confirmEmailChange(int $userId, string $currentHash, string $email): bool
    {
        $connection = LegacyDatabase::connection();
        $user = $connection->table('default_users_users')->where('id', $userId)->whereNull('deleted_at')->first();

        if ($user === null
            || ! hash_equals($currentHash, hash('sha256', Str::lower((string) $user->email)))
            || $connection->table('default_users_users')->where('email', Str::lower($email))->exists()) {
            return false;
        }

        try {
            return $connection->table('default_users_users')
                ->where('id', $userId)
                ->where('email', $user->email)
                ->update([
                    'email' => Str::lower($email),
                    'updated_by_id' => $userId,
                    'updated_at' => now(),
                ]) === 1;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{status: true, response: array{success: true}}
     */
    public function deleteAccount(int $userId, array $parameters): array
    {
        $validated = $this->validate($parameters, [
            'delete' => ['required', 'accepted'],
            'login_type' => ['required', Rule::in(['default', 'apple'])],
            'auth_code' => [Rule::requiredIf(($parameters['login_type'] ?? null) === 'apple'), 'nullable', 'string', 'max:4096'],
        ]);

        if ($validated['login_type'] === 'apple') {
            try {
                $this->appleRevoker->revoke((string) $validated['auth_code']);
            } catch (Throwable $exception) {
                Log::warning('Apple account revocation failed before account deletion.', [
                    'user_id' => $userId,
                    'exception' => $exception::class,
                ]);

                throw new MobileAccountException('The Apple account authorization could not be revoked.', 503);
            }
        }

        $suffix = $userId.'.'.Str::lower(Str::random(12));
        $disabledPassword = Hash::make(Str::random(64));
        $updated = LegacyDatabase::connection()->transaction(function (Connection $connection) use ($userId, $validated, $suffix, $disabledPassword): int {
            $user = $connection->table('default_users_users')->where('id', $userId)->lockForUpdate()->first();

            if ($user === null || $user->deleted_at !== null || ! (bool) $user->enabled) {
                return 0;
            }

            return $connection->table('default_users_users')->where('id', $userId)->update([
                'email' => "deleted+{$suffix}@deleted.invalid",
                'username' => "deleteduser{$suffix}",
                'display_name' => 'Deleted User',
                'first_name' => 'Deleted',
                'last_name' => 'User',
                'password' => $disabledPassword,
                'auth_token' => null,
                'remember_token' => null,
                'activation_code' => null,
                'reset_code' => null,
                'user_bio' => null,
                'user_profile_photo' => null,
                'activated' => false,
                'enabled' => false,
                'reason_for_closing_account' => json_encode([
                    'deleted_at' => now()->toIso8601String(),
                    'login_type' => $validated['login_type'],
                ], JSON_THROW_ON_ERROR),
                'updated_by_id' => $userId,
                'updated_at' => now(),
            ]);
        });

        if ($updated !== 1) {
            throw new MobileAccountException('The account is already unavailable.', 409);
        }

        return ['status' => true, 'response' => ['success' => true]];
    }

    /**
     * @param  array<string, string>  $additional
     */
    public function appendCallback(string $callback, string $parameter, array $additional = []): string
    {
        parse_str($parameter, $query);
        $query = array_merge($query, $additional);
        $separator = str_contains($callback, '?') ? '&' : '?';

        return $callback.$separator.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validate(array $parameters, array $rules): array
    {
        $validator = Validator::make($parameters, $rules);

        if ($validator->fails()) {
            throw MobileAccountException::validation($validator->errors()->toArray());
        }

        return $validator->validated();
    }

    private function assertAvailableIdentity(Connection $connection, string $email, string $username): void
    {
        $errors = [];

        if ($connection->table('default_users_users')->where('email', $email)->exists()) {
            $errors['email'] = ['The email has already been taken.'];
        }

        if ($connection->table('default_users_users')->where('username', $username)->exists()) {
            $errors['username'] = ['The username has already been taken.'];
        }

        if ($errors !== []) {
            throw MobileAccountException::validation($errors);
        }
    }

    private function allowedCallback(string $callback): string
    {
        $parts = parse_url($callback);
        $hosts = config('mapilio.mobile_accounts.allowed_callback_hosts', []);
        $schemes = config('mapilio.mobile_accounts.allowed_callback_schemes', ['https']);

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user'], $parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || ! is_array($hosts)
            || ! is_array($schemes)
            || ! in_array(Str::lower($parts['scheme']), $schemes, true)
            || ! in_array(Str::lower($parts['host']), $hosts, true)) {
            throw MobileAccountException::validation(['callback' => ['The callback is not allowed.']]);
        }

        return $callback;
    }

    private function isProviderGeneratedEmail(string $username, string $email): bool
    {
        [$prefix, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $providerId = Str::before($domain, '.');

        return $prefix !== ''
            && str_contains($username, $prefix)
            && preg_match('/\A[0-9]+\z/D', $providerId) === 1;
    }

    private function notify(string $email, string $name, string $subject, string $intro, string $action, string $url): void
    {
        Notification::route('mail', [$email => $name])
            ->notify(new MobileAccountActionNotification($subject, $intro, $action, $url));
    }

    private function profilePhotoDisk(): string
    {
        $disk = config('mapilio.mobile_accounts.profile_photo_disk', 'public');

        return is_string($disk) && $disk !== '' ? $disk : 'public';
    }

    private function activationMinutes(): int
    {
        return max(5, min(10080, (int) config('mapilio.mobile_accounts.activation_link_minutes', 1440)));
    }

    private function resetMinutes(): int
    {
        return max(5, min(1440, (int) config('mapilio.mobile_accounts.password_reset_link_minutes', 60)));
    }

    private function resetFormMinutes(): int
    {
        return max(5, min(120, (int) config('mapilio.mobile_accounts.password_reset_form_minutes', 30)));
    }
}
