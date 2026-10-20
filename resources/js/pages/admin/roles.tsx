import { Head, router, usePage } from '@inertiajs/react';
import { Lock, Plus, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { Field, PageHeader } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type Item = { name: string; label: string; hint: string };
type Group = { key: string; label: string; items: Item[] };
type Role = {
    id: number;
    name: string;
    label: string;
    built_in: boolean;
    locked: boolean;
    pin_login: boolean;
    users_count: number;
    permissions: string[];
};

export default function RolesPage({
    groups,
    roles,
}: {
    groups: Group[];
    roles: Role[];
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [activeId, setActiveId] = useState(
        roles.find((r) => !r.locked)?.id ?? roles[0]?.id,
    );
    const active = roles.find((r) => r.id === activeId) ?? roles[0];
    const [drafts, setDrafts] = useState<Record<number, string[]>>({});
    const [pinDrafts, setPinDrafts] = useState<Record<number, boolean>>({});
    const [newName, setNewName] = useState('');

    if (!active) {
        return null;
    }

    const current = active.locked
        ? groups.flatMap((g) => g.items.map((i) => i.name))
        : (drafts[active.id] ?? active.permissions);
    const pinLogin = pinDrafts[active.id] ?? active.pin_login;
    const dirty =
        !active.locked &&
        ((drafts[active.id] !== undefined &&
            [...drafts[active.id]].sort().join() !==
                [...active.permissions].sort().join()) ||
            pinLogin !== active.pin_login);

    const toggle = (name: string) => {
        if (active.locked) {
            return;
        }

        setDrafts((d) => {
            const base = d[active.id] ?? active.permissions;

            return {
                ...d,
                [active.id]: base.includes(name)
                    ? base.filter((x) => x !== name)
                    : [...base, name],
            };
        });
    };

    const setGroup = (g: Group, on: boolean) => {
        if (active.locked) {
            return;
        }

        setDrafts((d) => {
            const base = d[active.id] ?? active.permissions;
            const names = g.items.map((i) => i.name);

            return {
                ...d,
                [active.id]: on
                    ? [...new Set([...base, ...names])]
                    : base.filter((x) => !names.includes(x)),
            };
        });
    };

    return (
        <>
            <Head title="Peran & Akses" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Peran & hak akses"
                    subtitle="Atur fitur apa saja yang boleh diakses tiap peran. Perubahan langsung berlaku di login berikutnya dan tercatat di audit log."
                />
                {Object.values(errors ?? {})[0] && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}

                <div className="grid gap-4 lg:grid-cols-[260px_1fr]">
                    <aside
                        className="grid content-start gap-2"
                        aria-label="Daftar peran"
                    >
                        {roles.map((r) => (
                            <button
                                key={r.id}
                                onClick={() => setActiveId(r.id)}
                                aria-pressed={r.id === active.id}
                                className={cn(
                                    'flex items-center justify-between rounded-xl border p-3 text-left',
                                    r.id === active.id
                                        ? 'border-primary bg-primary/5'
                                        : 'bg-card',
                                )}
                            >
                                <span>
                                    <span className="block font-medium">
                                        {r.label}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {r.users_count} user ·{' '}
                                        {r.locked
                                            ? 'semua izin'
                                            : `${r.permissions.length} izin`}
                                    </span>
                                </span>
                                {r.locked ? (
                                    <Lock
                                        className="size-4 text-muted-foreground"
                                        aria-label="Terkunci"
                                    />
                                ) : (
                                    <ShieldCheck className="size-4 text-muted-foreground" />
                                )}
                            </button>
                        ))}
                        <div className="grid gap-2 rounded-xl border bg-card p-3">
                            <Field label="Peran baru">
                                <Input
                                    className="h-11"
                                    value={newName}
                                    onChange={(e) => setNewName(e.target.value)}
                                    placeholder="mis. Supervisor Bar"
                                />
                            </Field>
                            <Button
                                className="h-11"
                                disabled={!newName.trim()}
                                onClick={() =>
                                    router.post(
                                        '/admin/roles',
                                        { name: newName, permissions: [] },
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => setNewName(''),
                                        },
                                    )
                                }
                            >
                                <Plus /> Buat peran
                            </Button>
                        </div>
                    </aside>

                    <section
                        className="grid content-start gap-4"
                        aria-label={`Izin ${active.label}`}
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-lg font-semibold">
                                {active.label}
                            </h2>
                            <div className="flex gap-2">
                                {!active.built_in && (
                                    <Button
                                        variant="ghost"
                                        className="h-11"
                                        onClick={() =>
                                            confirm(
                                                `Hapus peran ${active.label}?`,
                                            ) &&
                                            router.delete(
                                                `/admin/roles/${active.id}`,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Hapus peran
                                    </Button>
                                )}
                                {!active.locked && (
                                    <Button
                                        className="h-11"
                                        disabled={!dirty}
                                        onClick={() =>
                                            router.put(
                                                `/admin/roles/${active.id}`,
                                                {
                                                    permissions: current,
                                                    pin_login: pinLogin,
                                                },
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () => {
                                                        setDrafts((d) => {
                                                            const n = { ...d };
                                                            delete n[active.id];
                                                            return n;
                                                        });
                                                        setPinDrafts((d) => {
                                                            const n = { ...d };
                                                            delete n[active.id];
                                                            return n;
                                                        });
                                                    },
                                                },
                                            )
                                        }
                                    >
                                        Simpan perubahan
                                    </Button>
                                )}
                            </div>
                        </div>
                        {active.locked && (
                            <p className="rounded-lg bg-muted p-3 text-sm">
                                Owner selalu punya semua izin dan tidak bisa
                                diubah.
                            </p>
                        )}

                        {!active.locked && (
                            <label className="flex cursor-pointer items-start gap-3 rounded-xl border bg-card p-3">
                                <input
                                    type="checkbox"
                                    className="mt-0.5 size-5 shrink-0"
                                    checked={pinLogin}
                                    onChange={(e) =>
                                        setPinDrafts((d) => ({
                                            ...d,
                                            [active.id]: e.target.checked,
                                        }))
                                    }
                                />
                                <span>
                                    <span className="block text-sm font-medium">
                                        Login dengan PIN
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        Semua akun di peran ini masuk cukup
                                        dengan PIN 6 digit masing-masing (PIN
                                        tiap orang berbeda, diatur di menu
                                        User). Matikan untuk peran kantor yang
                                        memakai email + password.
                                    </span>
                                </span>
                            </label>
                        )}

                        {groups.map((g) => {
                            const allOn = g.items.every((i) =>
                                current.includes(i.name),
                            );

                            return (
                                <fieldset
                                    key={g.key}
                                    className="rounded-xl border bg-card p-3"
                                >
                                    <legend className="px-1 text-sm font-semibold">
                                        {g.label}
                                    </legend>
                                    {!active.locked && (
                                        <button
                                            type="button"
                                            className="mb-2 text-xs text-primary underline"
                                            onClick={() => setGroup(g, !allOn)}
                                        >
                                            {allOn
                                                ? 'Cabut semua'
                                                : 'Pilih semua'}
                                        </button>
                                    )}
                                    <ul className="grid gap-1">
                                        {g.items.map((i) => (
                                            <li key={i.name}>
                                                <label
                                                    className={cn(
                                                        'flex cursor-pointer items-start gap-3 rounded-lg p-2',
                                                        !active.locked &&
                                                            'hover:bg-muted/50',
                                                    )}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        className="mt-0.5 size-5 shrink-0"
                                                        checked={current.includes(
                                                            i.name,
                                                        )}
                                                        disabled={active.locked}
                                                        onChange={() =>
                                                            toggle(i.name)
                                                        }
                                                    />
                                                    <span>
                                                        <span className="block text-sm font-medium">
                                                            {i.label}
                                                        </span>
                                                        <span className="block text-xs text-muted-foreground">
                                                            {i.hint}
                                                        </span>
                                                    </span>
                                                </label>
                                            </li>
                                        ))}
                                    </ul>
                                </fieldset>
                            );
                        })}
                    </section>
                </div>
            </div>
        </>
    );
}
