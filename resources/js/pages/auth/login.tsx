import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import PasskeyVerify from '@/components/passkey-verify';

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

    if (mode === 'pin') {
        return (
            <>
                <Head title="Masuk dengan PIN" />
                <Form
                    action="/login/pin"
                    method="post"
                    resetOnError
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="pin">PIN (6 digit)</Label>
                                <Input
                                    id="pin"
                                    name="pin"
                                    type="password"
                                    inputMode="numeric"
                                    pattern="[0-9]{6}"
                                    maxLength={6}
                                    required
                                    autoFocus
                                    autoComplete="off"
                                    placeholder="••••••"
                                    className="h-14 text-center text-2xl tracking-[0.5em]"
                                />
                                <InputError message={errors.pin} />
                            </div>
                            <Button
                                type="submit"
                                className="h-12 w-full"
                                disabled={processing}
                                data-test="pin-login-button"
                            >
                                {processing && <Spinner />}
                                Masuk
                            </Button>
                            <button
                                type="button"
                                className="text-sm text-muted-foreground underline"
                                onClick={() => setMode('email')}
                            >
                                Login owner / manager (email)
                            </button>
                        </div>
                    )}
                </Form>
            </>
        );
    }

    return (
        <>
            <Head title="Log in" />

            <PasskeyVerify />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">Password</Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                            tabIndex={5}
                                        >
                                            Forgot your password?
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
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">Remember me</Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Log in
                            </Button>
                        </div>
                    </>
                )}
            </Form>

            {pinLogin && (
                <button
                    type="button"
                    className="mt-4 w-full text-center text-sm text-muted-foreground underline"
                    onClick={() => setMode('pin')}
                >
                    Masuk dengan PIN (staf)
                </button>
            )}

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: 'Masuk ke POS',
    description: 'Staf: masukkan PIN. Owner/manager: email dan password.',
};
