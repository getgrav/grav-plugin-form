import $ from 'jquery';
import FileInstances from './file';
import ArrayInstances from './array';
import PageMedia, { Instance as PageMediaInstances } from './media';

$('body').on('touchstart click', '[data-tabid]', (event) => {
    event && event.stopPropagation();
    let target = $(event.currentTarget);

    const panel = $(`[id="${target.data('tabid')}"]`);

    target.siblings('[data-tabid]').removeClass('active');
    target.addClass('active');

    panel.siblings('[id]').removeClass('active');
    panel.addClass('active');
});

export default { FileInstances, ArrayInstances, Media: { PageMedia, PageMediaInstances } };
