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
                    className,
                )}
            >
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
