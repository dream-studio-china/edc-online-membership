function googleTranslateElementInit() {
  new google.translate.TranslateElement(
    {
      pageLanguage: 'en',
      includedLanguages: 'zh-CN,en,ja,ko',
      layout: google.translate.TranslateElement.InlineLayout.SIMPLE,
      autoDisplay: false,
    },
    'google_translate_element'
  );
}
