// config:
var reload_interval_ms = 10000;
var backend_url = './backend.php';


var app = angular.module('app', ["ngSanitize"]);

// http://stackoverflow.com/a/20033625/79461
app.filter("nl2br", function () {
        return function (data) {
            if (!data) return data;
            return data.replace(/\r?\n/g, '<br/>');
        }
    }
);

// http://stackoverflow.com/a/20033625/79461
app.filter("autolink", function () {
    return function (data) {
        return Autolinker.link(data, {truncate: {length: 50, location: 'middle', newWindow: true}});
    }
});

app.controller('MailboxController', ["$interval", "$http", "$log", function ($interval, $http, $log) {
    var self = this;

    self.backend_url = backend_url;
    self.public_config = {};


    self.generateRandomAddress = function () {
        var username = "";
        if (chance.bool()) {
            username += chance.first();
            if (chance.bool()) {
                username += chance.last();
            }
        } else {
            username += chance.word({syllables: 3})
        }
        if (chance.bool()) {
            username += chance.integer({min: 30, max: 99});
        }
        return username.toLowerCase() + "@" + chance.pick(self.public_config.domains, 1);
    };

    self.updateAddress = function (address) {
        if (address.length === 0) {
            self.randomize();
        } else {
            if (self.address !== address) {
                // changed
                if (address.indexOf("@") === -1) {
                    address += "@" + chance.pick(self.public_config.domains, 1);
                }
                self.address = address;
                hasher.setHash(self.address);
                self.updateMails();
            }
            self.inputFieldAddress = self.address;
        }
    };


    self.randomize = function () {
        self.updateAddress(self.generateRandomAddress());
    };


    self.onHashChange = function (hash) {
        self.updateAddress(hash);
    };

    self.$onInit = function () {
        self.loadConfig().then(function () {
            self.afterLoadConfig();
        });
    };

    self.afterLoadConfig = function () {
        hasher.changed.add(self.onHashChange.bind(self));
        hasher.initialized.add(self.onHashChange.bind(self)); //add initialized listener (to grab initial value in case it is already set)
        hasher.init(); //initialize hasher (start listening for history changes)

        $interval(self.updateMails, reload_interval_ms);
        self.updateMails()
    };

    self.updateMails = function () {
        if (self.address) {
            self.loadEmailsAsync(self.address);
        }
    };

    self.loadEmailsAsync = function (address) {
        $http.get(backend_url, {params: {address: address}})
            .then(function successCallback(response) {
                if (response.data.mails) {
                    self.error = null;
                    self.mails = response.data.mails;
                    self.address = response.data.address;
                    if (self.inputFieldAddress === self.address) {
                        self.inputFieldAddress = self.address;
                    }
                } else {
                    self.error = {
                        title: "JSON_ERROR",
                        desc: "The JSON from the response can not be read.",
                        detail: response
                    };
                    $log.error(response);
                }
            }, function errorCallback(response) {
                $log.error(response, this);
                self.error = {
                    title: "HTTP_ERROR",
                    desc: "There is a problem with loading the data. (HTTP_ERROR).",
                    detail: response
                };
            });
    };

    self.loadConfig = function () {
        return $http.get(backend_url, {params: {get_config: true}})
            .then(function successCallback(response) {
                self.public_config = response.data.config;
            }, function errorCallback(response) {
                $log.error(response, this);
                self.error = {
                    title: "HTTP_ERROR",
                    desc: "There is a problem with loading the config. (HTTP_ERROR).",
                    detail: response
                };
            });
    };

    self.deleteMail = function (mail, index) {
        // instantly remove from frontend.
        self.mails.splice(index, 1);

        // remove on backend.
        var firstTo = Object.keys(mail.to)[0];
        $http.get(backend_url, {params: {address: firstTo, delete_email_id: mail.id}})
            .then(
                function successCallback(response) {
                    self.updateMails();
                },
                function errorCallback(response) {
                    $log.error(response, this);
                    self.error = {
                        title: "HTTP_ERROR",
                        desc: "There is a problem with deleting the mail. (HTTP_ERROR).",
                        detail: response
                    };
                });
    };
}]);
