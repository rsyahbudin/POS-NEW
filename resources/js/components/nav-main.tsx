import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export type NavSection = { title: string; icon: LucideIcon; items: NavItem[] };

/** Flat links on top, then collapsible sections (a section with one link is shown as a plain link). */
export function NavMain({
    top,
    sections,
}: {
    top: NavItem[];
    sections: NavSection[];
}) {
    const { isCurrentUrl } = useCurrentUrl();
    const { state } = useSidebar();
    const collapsed = state === 'collapsed';

    const link = (item: NavItem) => (
        <SidebarMenuItem key={item.title}>
            <SidebarMenuButton
                asChild
                isActive={isCurrentUrl(item.href)}
                tooltip={{ children: item.title }}
            >
                <Link href={item.href} prefetch>
                    {item.icon && <item.icon />}
                    <span>{item.title}</span>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    );

    // Icon-only sidebar: no room for sub-menus, so every link is shown flat.
    if (collapsed) {
        return (
            <SidebarGroup className="px-2 py-0">
                <SidebarMenu>
                    {[...top, ...sections.flatMap((s) => s.items)].map(link)}
                </SidebarMenu>
            </SidebarGroup>
        );
    }

    return (
        <>
            {top.length > 0 && (
                <SidebarGroup className="px-2 py-0">
                    <SidebarGroupLabel>Harian</SidebarGroupLabel>
                    <SidebarMenu>{top.map(link)}</SidebarMenu>
                </SidebarGroup>
            )}
            <SidebarGroup className="px-2 py-0">
                <SidebarGroupLabel>Kelola</SidebarGroupLabel>
                <SidebarMenu>
                    {sections.map((s) =>
                        s.items.length === 1 ? (
                            link(s.items[0])
                        ) : (
                            <Section key={s.title} section={s} />
                        ),
                    )}
                </SidebarMenu>
            </SidebarGroup>
        </>
    );
}

function Section({ section }: { section: NavSection }) {
    const { isCurrentUrl } = useCurrentUrl();
    const hasActive = section.items.some((i) => isCurrentUrl(i.href));
    const [open, setOpen] = useState(hasActive);

    useEffect(() => {
        if (hasActive) {
            setOpen(true);
        }
    }, [hasActive]);

    return (
        <Collapsible open={open} onOpenChange={setOpen} asChild>
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton
                        tooltip={{ children: section.title }}
                        isActive={hasActive && !open}
                    >
                        <section.icon />
                        <span>{section.title}</span>
                        <ChevronRight
                            className={`ml-auto size-4 transition-transform ${open ? 'rotate-90' : ''}`}
                        />
                    </SidebarMenuButton>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <SidebarMenuSub>
                        {section.items.map((item) => (
                            <SidebarMenuSubItem key={item.title}>
                                <SidebarMenuSubButton
                                    asChild
                                    className="h-9"
                                    isActive={isCurrentUrl(item.href)}
                                >
                                    <Link href={item.href} prefetch>
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                        ))}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}
