import { useFlashToast } from '@/hooks/use-flash-toast';
import { useAppearance } from '@/hooks/use-appearance';
import { useIsMobile } from '@/hooks/use-mobile';
import { Toaster as Sonner, type ToasterProps } from 'sonner';

function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();
    const mobile = useIsMobile();

    useFlashToast();

    return (
        <Sonner
            theme={appearance}
            className="toaster group"
            // On a phone the bottom edge belongs to the cart button and sheets, so messages come from the top.
            position={mobile ? 'top-center' : 'bottom-right'}
            offset={12}
            mobileOffset={{ top: 12, left: 12, right: 12 }}
            duration={3000}
            style={
                {
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };
