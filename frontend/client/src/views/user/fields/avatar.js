/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014  Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: http://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 ************************************************************************/
Espo.define('Views.User.Fields.Avatar', 'Views.Fields.Image', function (Dep) {

    return Dep.extend({
    
        handleFileUpload: function (file, contents, callback) {

            this.createView('crop', 'Modals.ImageCrop', {
                contents: contents
            }, function (view) {
                view.render();

                var croped = false;

                this.listenToOnce(view, 'crop', function (croppedContents, params) {
                    croped = true;
                    setTimeout(function () {
                        params = params || {};
                        params.name = 'avatar.jpg';
                        params.type = 'image/jpeg';

                        callback(croppedContents, params);
                    }.bind(this), 10);
                });
                this.listenToOnce(view, 'remove', function () {
                    if (!croped) {
                        setTimeout(function () {
                            this.render();
                        }.bind(this), 10);
                    }
                }.bind(this));
            }.bind(this));
        },
        
    });
    
});
