module.exports = {
  prettierConfig: {
    trailingComma: "es5",
    singleQuote: true,
    plugins: [
      "@zackad/prettier-plugin-twig-melody",
      "prettier-plugin-tailwindcss",
    ],
    overrides: [
      {
        files: "*.twig",
        options: {
          parser: "melody",
        },
      },
    ],
  },
};
