define(['core/modal_factory'], function(ModalFactory) {
    return {
        create: function(config) {
            config = Object.assign({}, config || {}, {
                type: ModalFactory.types.SAVE_CANCEL
            });

            return ModalFactory.create(config);
        }
    };
});
