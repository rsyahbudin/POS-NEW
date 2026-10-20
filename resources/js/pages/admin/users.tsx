import { Head, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Modal } from '@/components/pos/modal';
import { Field, PageHeader, Select } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type U = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    role: string | null;
    has_pin: boolean;
    has_2fa: boolean;
};

export default function UsersPage({
    users,
    roles,
}: {
    users: U[];
    roles: { name: string; label: string }[];
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [form, setForm] = useState<{
        id?: number;
        name: string;
        email: string;
        role: string;
        password: string;
        pin: string;
    } | null>(null);
    const [sec, setSec] = useState<{
        user: U;
        kind: 'password' | 'pin';
        value: string;
    } | null>(null);

    return (
        <>
            <Head title="User" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Manajemen user"
                    subtitle="User dinonaktifkan, tidak pernah dihapus. Hak akses tiap peran diatur di menu Peran & Akses."
                    actions={
                        <Button
                            className="h-11"
                            onClick={() =>
                                setForm({
                                    name: '',
                                    email: '',
                                    role: 'cashier',
                                    password: '',
                                    pin: '',
                                })
                            }
                        >
                            <Plus /> User
                        </Button>
                    }
                />
                {Object.values(errors ?? {})[0] && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}
                <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                    {users.map((u) => (
                        <div
                            key={u.id}
                            className={`rounded-xl border bg-card p-3 ${u.is_active ? '' : 'opacity-60'}`}
                        >
                            <p className="font-medium">
                                {u.name}{' '}
                                <span className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                    {roles.find((r) => r.name === u.role)
                                        ?.label ?? u.role}
                                </span>
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {u.email}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {u.has_pin ? 'PIN diset' : 'Tanpa PIN'} ·{' '}
                                {u.has_2fa ? '2FA aktif' : '2FA belum'} ·{' '}
                                {u.is_active ? 'aktif' : 'nonaktif'}
                            </p>
                            <div className="mt-2 flex flex-wrap gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="h-10"
                                    onClick={() =>
                                        setForm({
                                            id: u.id,
                                            name: u.name,
                                            email: u.email,
                                            role: u.role ?? 'cashier',
                                            password: '',
                                            pin: '',
                                        })
                                    }
                                >
                                    Ubah
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="h-10"
                                    onClick={() =>
                                        setSec({
                                            user: u,
                                            kind: 'password',
                                            value: '',
                                        })
                                    }
                                >
                                    Reset password
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="h-10"
                                    onClick={() =>
                                        setSec({
                                            user: u,
                                            kind: 'pin',
                                            value: '',
                                        })
                                    }
                                >
                                    Set PIN
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="h-10"
                                    onClick={() =>
                                        router.post(
                                            `/admin/users/${u.id}/toggle`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {u.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                                </Button>
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {form && (
                <Modal
                    open
                    onClose={() => setForm(null)}
                    title={form.id ? 'Ubah user' : 'User baru'}
                >
                    <Field label="Nama">
                        <Input
                            className="h-11"
                            value={form.name}
                            onChange={(e) =>
                                setForm({ ...form, name: e.target.value })
                            }
                        />
                    </Field>
                    <Field label="Email">
                        <Input
                            className="h-11"
                            type="email"
                            value={form.email}
                            onChange={(e) =>
                                setForm({ ...form, email: e.target.value })
                            }
                        />
                    </Field>
                    <Field label="Role">
                        <Select
                            value={form.role}
                            onChange={(e) =>
                                setForm({ ...form, role: e.target.value })
                            }
                        >
                            {roles.map((r) => (
                                <option key={r.name} value={r.name}>
                                    {r.label}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    {!form.id && (
                        <>
                            <Field label="Password (min 8)">
                                <Input
                                    className="h-11"
                                    type="password"
                                    value={form.password}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            password: e.target.value,
                                        })
                                    }
                                    autoComplete="new-password"
                                />
                            </Field>
                            <Field
                                label="PIN 6 digit (manager)"
                                hint="Opsional. PIN dipakai menyetujui void & diskon."
                            >
                                <Input
                                    className="h-11"
                                    inputMode="numeric"
                                    maxLength={6}
                                    value={form.pin}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            pin: e.target.value.replace(
                                                /\D/g,
                                                '',
                                            ),
                                        })
                                    }
                                />
                            </Field>
                        </>
                    )}
                    <Button
                        className="h-12"
                        onClick={() => {
                            const o = {
                                preserveScroll: true,
                                onSuccess: () => setForm(null),
                            };
                            if (form.id) {
                                router.put(`/admin/users/${form.id}`, form, o);
                            } else {
                                router.post(
                                    '/admin/users',
                                    { ...form, pin: form.pin || null },
                                    o,
                                );
                            }
                        }}
                    >
                        Simpan
                    </Button>
                </Modal>
            )}
            {sec && (
                <Modal
                    open
                    onClose={() => setSec(null)}
                    title={`${sec.kind === 'pin' ? 'Set PIN' : 'Reset password'} · ${sec.user.name}`}
                >
                    <Field
                        label={
                            sec.kind === 'pin'
                                ? 'PIN baru (6 digit)'
                                : 'Password baru (min 8)'
                        }
                    >
                        <Input
                            className="h-11"
                            type={sec.kind === 'pin' ? 'text' : 'password'}
                            inputMode={
                                sec.kind === 'pin' ? 'numeric' : undefined
                            }
                            maxLength={sec.kind === 'pin' ? 6 : 100}
                            value={sec.value}
                            onChange={(e) =>
                                setSec({
                                    ...sec,
                                    value:
                                        sec.kind === 'pin'
                                            ? e.target.value.replace(/\D/g, '')
                                            : e.target.value,
                                })
                            }
                        />
                    </Field>
                    <Button
                        className="h-12"
                        disabled={
                            sec.kind === 'pin'
                                ? sec.value.length !== 6
                                : sec.value.length < 8
                        }
                        onClick={() =>
                            router.post(
                                `/admin/users/${sec.user.id}/${sec.kind}`,
                                { [sec.kind]: sec.value },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setSec(null),
                                },
                            )
                        }
                    >
                        Simpan
                    </Button>
                </Modal>
            )}
        </>
    );
}
