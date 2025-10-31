/*tinymce.init({
  selector:'textarea.tinyform',
    setup: function (editor) {
        editor.on('change', function (e) {
            editor.save();
        });
    },
  height: 300,
  plugins: 'textcolor table paste',
  plugins: [
      'advlist','autolink','lists','link image','charmap','print','preview','anchor','pagebreak',
      'searchreplace','wordcount','visualblocks','visualchars','code','fullscreen',
      'insertdatetime','media','contextmenu','paste',
      'table','help'
  ],
    toolbar: 'formatselect | undo redo | styles | bold italic | alignleft aligncenter alignright alignjustify | ' +
          'bullist numlist outdent indent | link image | preview media fullscreen | ' +
          'forecolor backcolor emoticons | pastetext | help',
          paste_as_text: true // Ensures pasted content is plain text
});
*/

tinymce.init({
  selector: 'textarea.tinyform',
  height: 300,

  // Use one plugins value (array OR string)
  plugins: [
    'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview', 'anchor',
    'searchreplace', 'wordcount', 'visualblocks', 'visualchars', 'code', 'fullscreen',
    'insertdatetime', 'media', /* 'paste' -> remove (core in v6) */
    'table', 'help', 'emoticons', 'pagebreak'
  ],

  toolbar:
    'formatselect styleselect | undo redo | ' +
    'bold italic | alignleft aligncenter alignright alignjustify | ' +
    'bullist numlist outdent indent | link image media | preview fullscreen | ' +
    'forecolor backcolor emoticons | removeformat | code | help',

  // If you want pasted content to KEEP formatting, set false
  paste_as_text: true,

  // Make sure <textarea> keeps in sync for normal form posts
  setup(editor) {
    const save = () => editor.save();
    editor.on('input change undo redo', save);
  }
});

