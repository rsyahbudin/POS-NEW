import { toast } from 'sonner';
import { Modal } from '@/components/pos/modal';
import { Button } from '@/components/ui/button';
import { t } from '@/lang';
import { api, ApiError } from '@/lib/api';
import type { Floor, Order } from '@/types/pos';

/** Move the order to an empty table, or merge it into another table's open order. */
export function MoveDialog({
    order,
    floor,
    onClose,
    onDone,
}: {
    order: Order;
    floor: Floor;
    onClose: () => void;
    onDone: (o: Order, message: string) => void;
}) {
    const run = async (url: string, body: unknown, message: string) => {
        try {
            const res = await api<{ order: Order }>('POST', url, body);
            onDone(res.order, message);
        } catch (e) {
            toast.error(e instanceof ApiError ? e.message : t('Gagal.'));
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={t('Pindah / gabung meja')}
            description={order.table?.name}
        >
            {floor.map((area) => (
                <section key={area.id}>
                    <h3 className="mb-1 text-sm text-muted-foreground">
                        {area.name}
                    </h3>
                    <div className="grid grid-cols-3 gap-2">
                        {area.tables
                            .filter((tb) => tb.id !== order.table?.id)
                            .map((tb) => (
                                <Button
                                    key={tb.id}
                                    type="button"
                                    variant="outline"
                                    className="h-14 flex-col"
                                    onClick={() =>
                                        tb.order
                                            ? run(
                                                  `/pos/orders/${order.id}/merge`,
                                                  {
                                                      target_order_id:
                                                          tb.order.id,
                                                  },
                                                  `${t('Digabung ke')} ${tb.name}`,
                                              )
                                            : run(
                                                  `/pos/orders/${order.id}/move-table`,
                                                  { table_id: tb.id },
                                                  `${t('Dipindah ke')} ${tb.name}`,
                                              )
                                    }
                                >
                                    <span>{tb.name}</span>
                                    <span className="text-[10px] text-muted-foreground">
                                        {tb.order ? t('gabung') : t('pindah')}
                                    </span>
                                </Button>
                            ))}
                    </div>
                </section>
            ))}
        </Modal>
    );
}
