import { usePage } from '@inertiajs/react';
import { BrandMark } from '@/components/brand-mark';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { brand } = usePage().props;

    return (
        <div className="flex min-h-svh flex-col items-center justify-center bg-muted/50 p-5 sm:p-8">
            <div className="w-full max-w-sm">
                <div className="mb-6 flex flex-col items-center gap-3 text-center">
                    <BrandMark />
                    <p className="text-lg font-bold tracking-tight">
                        {brand.name}
                    </p>
                </div>
                <div className="rounded-3xl bg-card p-6 shadow-sm ring-1 ring-border sm:p-8">
                    <div className="mb-6 space-y-1.5 text-center">
                        <h1 className="text-xl font-bold tracking-tight">
                            {title}
                        </h1>
                        {description && (
                            <p className="text-sm leading-snug text-muted-foreground">
                                {description}
                            </p>
                        )}
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
