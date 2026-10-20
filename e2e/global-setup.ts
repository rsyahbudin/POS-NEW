import { execFileSync } from 'node:child_process';
import { existsSync, renameSync } from 'node:fs';

/**
 * Pages must load built assets, not a developer's Vite server: park
 * `public/hot` for the run (restored in teardown) and build once.
 */
export default function globalSetup(): void {
    if (existsSync('public/hot')) {
        renameSync('public/hot', 'public/hot.e2e-backup');
    }

    execFileSync('npm', ['run', 'build'], { stdio: 'ignore' });
}
