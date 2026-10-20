declare module 'qz-tray' {
    const qz: {
        websocket: {
            connect(opts?: unknown): Promise<void>;
            isActive(): boolean;
        };
        security: {
            setCertificatePromise(
                fn: (
                    resolve: (c: string) => void,
                    reject: (e: unknown) => void,
                ) => void,
            ): void;
            setSignatureAlgorithm(a: string): void;
            setSignaturePromise(
                fn: (
                    toSign: string,
                ) => (
                    resolve: (s: string) => void,
                    reject: (e: unknown) => void,
                ) => void,
            ): void;
        };
        printers: { find(query?: string): Promise<string | string[]> };
        configs: { create(printer: string, opts?: unknown): unknown };
        print(config: unknown, data: unknown[]): Promise<void>;
    };

    export default qz;
}
