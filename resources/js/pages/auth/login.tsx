import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import { PinPad } from '@/components/pin-pad';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
    pinLogin?: boolean;
};

export default function Login({
    status,
    canResetPassword,
    pinLogin = false,
}: Props) {
    const [mode, setMode] = useState<'pin' | 'email'>(
        pinLogin ? 'pin' : 'email',
    );

    return (
        <>
            <Head title={mode === 'pin' ? 'Masuk dengan PIN' : 'Masuk'} />

            {status && (
                <div
                    role="status"
                    className="mb-4 rounded-xl bg-emerald-50 p-3 text-center text-sm font-medium text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200"
                >
                    {status}
                </div>
            )}

            {mode === 'pin' ? (
                <div className="grid gap-6">
                    <p className="text-center text-sm font-medium">
                        Masukkan PIN 6 digit
                    </p>
                    <PinPad />
                    <button
                        type="button"
                        className="mx-auto text-sm font-medium text-primary underline-offset-4 hover:underline"
                        onClick={() => setMode('email')}
                    >
                        Owner / manager? Masuk dengan email
                    </button>
                </div>
            ) : (
                <div className="grid gap-5">
                    <PasskeyVerify
                        label="Masuk dengan passkey"
                        loadingLabel="Memeriksa..."
                        separator="Atau masuk dengan email"
                    />
                    <Form
                        {...store.form()}
                        resetOnSuccess={['password']}
                        className="grid gap-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        required
                                        autoFocus
                                        tabIndex={1}
                                        autoComplete="email"
                                        placeholder="nama@email.com"
                                        className="h-12 text-base"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-2">
                                    <div className="flex items-center">
                                        <Label htmlFor="password">
                                            Password
                                        </Label>
                                        {canResetPassword && (
                                            <TextLink
                                                href={request()}
                                                className="ml-auto text-sm"
                                                tabIndex={5}
                                            >
                                                Lupa password?
                                            </TextLink>
                                        )}
                                    </div>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required
                                        tabIndex={2}
                                        autoComplete="current-password"
                                        placeholder="Password"
                                        className="h-12 text-base"
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="flex items-center gap-3">
                                    <Checkbox
                                        id="remember"
                                        name="remember"
                                        tabIndex={3}
                                    />
                                    <Label htmlFor="remember">
                                        Ingat saya di perangkat ini
                                    </Label>
                                </div>

                                <Button
                                    type="submit"
                                    className="h-12 w-full text-base"
                                    tabIndex={4}
                                    disabled={processing}
                                    data-test="login-button"
                                >
                                    {processing && <Spinner />}
                                    Masuk
                                </Button>
                            </>
                        )}
                    </Form>

                    {pinLogin && (
                        <button
                            type="button"
                            className="mx-auto text-sm font-medium text-primary underline-offset-4 hover:underline"
                            onClick={() => setMode('pin')}
                        >
                            Staf? Masuk dengan PIN
                        </button>
                    )}
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: 'Selamat datang',
    description: 'Masuk untuk mulai bekerja.',
};
