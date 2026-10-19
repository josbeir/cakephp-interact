import { defineConfig } from 'vitest/config';
export default defineConfig({
  server: { ws: false },
  test: {
    environment: 'jsdom',
    include: ['tests/js/**/*.test.ts'],
    restoreMocks: true,
    coverage: {
      provider: 'v8',
      include: ['resources/ts/**/*.ts'],
      exclude: ['resources/ts/**/*.d.ts'],
      reportsDirectory: 'build/coverage-js',
      reporter: ['text', 'json-summary', 'lcov'],
      thresholds: { lines: 95 },
    },
  },
});
