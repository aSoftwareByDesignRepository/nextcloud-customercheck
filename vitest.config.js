import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['tests/js/**/*.test.js'],
    exclude: ['tests/e2e/**', 'node_modules/**', 'vendor/**'],
    environment: 'node',
  },
});
