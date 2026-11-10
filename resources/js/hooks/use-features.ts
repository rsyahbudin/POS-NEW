import { usePage } from '@inertiajs/react';

/** Which optional parts of the app are switched on, so screens can leave out what nobody uses. */
export function useFeatures() {
    const f = usePage().props.features as
        | { stations?: boolean; stock_by_station?: boolean; kds?: boolean }
        | undefined;

    return {
        /** Stations matter only for the kitchen screen and for stock held per station. */
        stations: !!f?.stations,
        stockByStation: !!f?.stock_by_station,
        kds: !!f?.kds,
    };
}
