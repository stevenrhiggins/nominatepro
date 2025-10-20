tinymce.init({	
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