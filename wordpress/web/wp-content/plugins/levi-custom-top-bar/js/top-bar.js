jQuery(document).ready(function($) {
    // Prüfe, ob die Top-Bar bereits geschlossen wurde
    if (localStorage.getItem('leviTopBarClosed')) {
        $('#levi-top-bar').hide();
        $('body').removeClass('has-top-bar');
    } else {
        $('body').addClass('has-top-bar');
    }

    // Event-Handler für den Schließen-Button
    $('#close-top-bar').on('click', function() {
        $('#levi-top-bar').slideUp(300, function() {
            $('body').removeClass('has-top-bar');
        });
        localStorage.setItem('leviTopBarClosed', 'true');
    });
});