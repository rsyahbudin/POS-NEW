import type { ReactNode } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

export function Modal({
    open,
    onClose,
    title,
    description,
    children,
    className,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className={cn(
                    'max-h-[92dvh] gap-4 overflow-y-auto sm:max-w-lg',
                    // phone: a sheet that rises from the bottom, within thumb reach
                    'max-sm:top-auto max-sm:bottom-0 max-sm:left-0 max-sm:max-w-none max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-t-2xl max-sm:rounded-b-none max-sm:border-x-0 max-sm:border-b-0 max-sm:pb-[max(1.5rem,env(safe-area-inset-bottom))]',
                    className,
                )}
            >
                <div
                    aria-hidden
                    className="mx-auto -mt-2 h-1 w-10 rounded-full bg-border sm:hidden"
                />
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription
                        className={description ? undefined : 'sr-only'}
                    >
                        {description ?? title}
                    </DialogDescription>
                </DialogHeader>
                {children}
            </DialogContent>
        </Dialog>
    );
}
