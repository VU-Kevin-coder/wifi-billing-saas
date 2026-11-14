import { readFile } from 'node:fs/promises';
import { expect, test } from '@playwright/test';

const baseURL = 'http://127.0.0.1:8123';

test('a pending portal purchase stays pending and keeps its token in the request header', async ({ page }) => {
    await page.route('https://fonts.bunny.net/**', (route) => route.abort());

    const fixturePath = process.env.PLAYWRIGHT_FIXTURE_PATH;
    const cookieName = process.env.PLAYWRIGHT_SESSION_COOKIE;
    if (!fixturePath || !cookieName) {
        throw new Error('Playwright fixture paths were not propagated to the test worker.');
    }

    const fixture = JSON.parse(await readFile(fixturePath, 'utf8')) as { sessionCookie: string };
    await page.context().addCookies([
        {
            name: cookieName,
            value: fixture.sessionCookie,
            url: baseURL,
        },
    ]);

    const paymentStatusResponse = page.waitForResponse((response) => {
        return new URL(response.url()).pathname === '/api/payments/status';
    });

    await page.goto('/portal/phase1-e2e/success');

    const response = await paymentStatusResponse;
    expect(response.status()).toBe(202);
    expect(response.ok()).toBe(true);
    await expect(page.getByRole('heading', { name: /Waiting for payment/ })).toBeVisible();
    await expect(page.getByText('Your Voucher Code')).toBeHidden();
    await expect(page.getByText('Your Wi-Fi access is now activated.')).toBeHidden();

    const request = response.request();
    const requestUrl = new URL(request.url());
    const paymentToken = request.headers()['x-payment-token'];

    expect(paymentToken).toBeTruthy();
    expect(requestUrl.search).toBe('');
    expect(requestUrl.href).not.toContain(paymentToken);
    expect(await response.json()).toMatchObject({ status: 'pending' });
});
