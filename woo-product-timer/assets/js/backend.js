'use strict';

(function($) {
  $(function() {
    woopt_show_action();
    woopt_show_conditional();
    woopt_show_apply();
    woopt_time_picker();
    woopt_build_label();
    woopt_terms_init();
    woopt_user_roles_init();
    woopt_apply_terms_init();
    woopt_products_init();
    woopt_sortable();
  });

  $(document).on('change', '.woopt_action_selector', function() {
    var $this = $(this);
    var $action = $this.closest('.woopt_action_wrap');
    woopt_show_action($action);
    woopt_build_label($this);
  });

  $(document).
      on('keyup change keypress', '.woopt_action_name_input', function() {
        let $this = $(this), value = $this.val();

        if (value !== '') {
          $this.closest('.woopt_action').
              find('.woopt_action_label_name').
              text(value);
        } else {
          $this.closest('.woopt_action').
              find('.woopt_action_label_name').
              text($this.data('name'));
        }
      });

  $(document).on('change', '.woopt_time_type', function() {
    var $time = $(this).closest('.woopt_time');

    woopt_show_conditional($time);
  });

  $(document).
      on('change',
          '.woopt_time select:not(.woopt_time_type), .woopt_time input:not(.woopt_time_val)',
          function() {
            var val = $(this).val();
            var show = $(this).
                closest('.woopt_time').
                find('.woopt_time_type').
                find(':selected').
                data('show');

            $(this).
                closest('.woopt_time').
                find('.woopt_time_val').data(show, val).
                val(val).
                trigger('change');
          });

  $(document).on('change', '.woopt_apply_selector', function() {
    var $action = $(this).closest('.woopt_action');
    woopt_show_apply($action);
    woopt_build_label();
    woopt_terms_init();
    woopt_products_init();
  });

  $(document).on('change', '.woopt_apply_combination_select', function() {
    woopt_apply_terms_init();
  });

  $(document).on('click touch', '.woopt_apply_combination_remove', function() {
    $(this).closest('.woopt_apply_combination').remove();
  });

  $(document).on('click touch', '.woopt_action_heading', function(e) {
    if (($(e.target).closest('.woopt_action_duplicate').length === 0) &&
        ($(e.target).closest('.woopt_action_remove').length === 0) &&
        ($(e.target).closest('.woopt_action_summary').length === 0)) {
      $(this).closest('.woopt_action').toggleClass('active');
    }
  });

  // search product
  $(document).on('change', '.woopt-product-search', function() {
    var $this = $(this);
    var val = $this.val();
    var _val = '';

    if (val !== null) {
      if (Array.isArray(val)) {
        _val = val.join();
      } else {
        _val = String(val);
      }
    }

    $this.attr('data-val', _val);
    $this.closest('.woopt_action').
        find('.woopt_apply_val').
        val(_val).
        trigger('change');
  });

  // search category
  $(document).on('change', '.woopt-category-search', function() {
    var $this = $(this);
    var val = $this.val();
    var _val = '';

    if (val !== null) {
      if (Array.isArray(val)) {
        _val = val.join();
      } else {
        _val = String(val);
      }
    }

    $this.attr('data-val', _val);
    $this.closest('.woopt_action').
        find('.woopt_apply_val').
        val(_val).
        trigger('change');
  });

  // search terms
  $(document).on('change', '.woopt_terms', function() {
    var $this = $(this);
    var val = $this.val();
    var _val = '';
    var apply = $this.closest('.woopt_action').
        find('.woopt_apply_selector').
        val();

    if (val !== null) {
      if (Array.isArray(val)) {
        _val = val.join();
      } else {
        _val = String(val);
      }
    }

    $this.data(apply, _val);
    $this.closest('.woopt_action').
        find('.woopt_apply_val').
        val(_val).
        trigger('change');
  });

  $(document).on('click touch', '.woopt_new_action', function(e) {
    e.preventDefault();
    $('.woopt_actions').addClass('woopt_actions_loading');

    var global = $(this).attr('data-global');
    var data = {
      action: 'woopt_add_action', global: global, nonce: woopt_vars.nonce,
    };

    $.post(ajaxurl, data, function(response) {
      $('.woopt_actions').append(response);
      woopt_time_picker();
      woopt_show_action();
      woopt_show_conditional();
      woopt_show_apply();
      woopt_build_label();
      woopt_terms_init();
      woopt_user_roles_init();
      woopt_products_init();
      $('.woopt_actions').removeClass('woopt_actions_loading');
    });
  });

  $(document).on('click touch', '.woopt_action_duplicate', function(e) {
    e.preventDefault();
    $('.woopt_actions').addClass('woopt_actions_loading');

    var $action = $(this).closest('.woopt_action');
    var form_data = $action.
        find('input, select, button, textarea').
        serialize() || 0;
    var global = $(this).attr('data-global');
    var data = {
      action: 'woopt_add_action',
      global: global,
      form_data: form_data,
      nonce: woopt_vars.nonce,
    };

    $.post(ajaxurl, data, function(response) {
      $(response).insertAfter($action);
      woopt_time_picker();
      woopt_show_action();
      woopt_show_conditional();
      woopt_show_apply();
      woopt_build_label();
      woopt_terms_init();
      woopt_apply_terms_init();
      woopt_user_roles_init();
      woopt_products_init();
      $('.woopt_actions').removeClass('woopt_actions_loading');
    });
  });

  $(document).on('click touch', '.woopt_new_time', function(e) {
    var $timer = $(this).closest('.woopt_action').find('.woopt_timer');
    var data = {
      key: $(this).closest('.woopt_action').data('key'),
      action: 'woopt_add_time',
      nonce: woopt_vars.nonce,
    };

    $.post(ajaxurl, data, function(response) {
      $timer.append(response);
      woopt_time_picker();
      woopt_show_conditional();
    });

    e.preventDefault();
  });

  $(document).on('click touch', '.woopt_new_apply_combination', function(e) {
    var $apply_combinations = $(this).
        closest('.woopt_action').
        find('.woopt_apply_combinations');
    var data = {
      key: $(this).closest('.woopt_action').data('key'),
      action: 'woopt_add_apply_combination',
      nonce: woopt_vars.nonce,
    };

    $.post(ajaxurl, data, function(response) {
      $apply_combinations.append(response);
      woopt_apply_terms_init();
    });

    e.preventDefault();
  });

  $(document).on('click touch', '.woopt_save_actions', function(e) {
    e.preventDefault();

    var $this = $(this);

    $this.addClass('woopt_disabled');
    $('.woopt_actions').addClass('woopt_actions_loading');

    var form_data = $('#woopt_settings').
        find('input, select, button, textarea').
        serialize() || 0;
    var data = {
      action: 'woopt_save_actions',
      pid: $('#post_ID').val(),
      form_data: form_data,
      nonce: woopt_vars.nonce,
    };

    $.post(ajaxurl, data, function(response) {
      $('.woopt_actions').removeClass('woopt_actions_loading');
      $this.removeClass('woopt_disabled');
    });
  });

  $(document).on('click touch', '.woopt_action_remove', function(e) {
    e.preventDefault();

    if (confirm('Are you sure?')) {
      $(this).closest('.woopt_action').remove();
    }
  });

  // Action Summary Modal
  $(document).on('click touch', '.woopt_action_summary', function(e) {
    e.preventDefault();
    e.stopPropagation();

    var $action = $(this).closest('.woopt_action');
    var key = $action.data('key');
    var actionName = $action.find('.woopt_action_name_input').val() || '#' + key;

    // Action details
    var $actionSelect = $action.find('.woopt_action_selector');
    var actionVal = $actionSelect.val();
    var actionText = $actionSelect.find('option:selected').text().trim() || '(No action selected)';
    var actionExtra = '';

    if (actionVal === 'set_regularprice' || actionVal === 'set_saleprice') {
      var priceVal = $action.find('.woopt_price').val();
      var baseText = $action.find('.woopt_action_price_base option:selected').text().trim();
      if (priceVal) {
        actionExtra = ' (' + priceVal + ' ' + baseText + ')';
      }
    } else if (actionVal === 'set_catalog_visibility') {
      var visText = $action.find('select[name*="[visibility]"] option:selected').text().trim();
      if (visText) {
        actionExtra = ' &rarr; ' + visText;
      }
    }

    // Apply for (if global)
    var isGlobal = $action.find('input[name*="[type]"]').val() === 'global' || $(this).data('global');
    var applyText = '';
    var applyDetails = [];

    if (isGlobal && $action.find('.woopt_apply_selector').length) {
      applyText = $action.find('.woopt_apply_selector option:selected').text().trim();
      var applyVal = $action.find('.woopt_apply_selector').val();

      if (applyVal === 'apply_product') {
        $action.find('.woopt-product-search option:selected').each(function() {
          applyDetails.push($(this).text().trim());
        });
      } else if (applyVal === 'apply_combination') {
        $action.find('.woopt_apply_combination').each(function() {
          var source = $(this).find('.woopt_apply_source option:selected').text().trim();
          var val = $(this).find('.woopt_apply_combination_val').val();
          if (source) {
            applyDetails.push(source + (val ? ': ' + val : ''));
          }
        });
      } else if (applyVal.indexOf('apply_') === 0 && applyVal !== 'apply_all' && applyVal !== 'apply_variation' && applyVal !== 'apply_not_variation') {
        $action.find('.woopt_terms option:selected').each(function() {
          applyDetails.push($(this).text().trim());
        });
      }
    }

    // User Roles
    var userRoles = [];
    $action.find('.woopt_user_roles select option:selected').each(function() {
      userRoles.push($(this).text().trim());
    });

    // Time conditions
    var timers = [];
    $action.find('.woopt_time').each(function() {
      var $t = $(this);
      var timeType = $t.find('.woopt_time_type option:selected').text().trim();
      var timeShow = $t.find('.woopt_time_type option:selected').data('show');
      var timeVal = '';

      if (timeShow === 'date_time') {
        timeVal = $t.find('.woopt_date_time_input').val();
      } else if (timeShow === 'date') {
        timeVal = $t.find('.woopt_dpk_date').val();
      } else if (timeShow === 'date_range') {
        timeVal = $t.find('.woopt_dpk_date_range').val();
      } else if (timeShow === 'date_multi') {
        timeVal = $t.find('.woopt_dpk_date_multi').val();
      } else if (timeShow === 'time_range') {
        var from = $t.find('.woopt_time_from').val();
        var to = $t.find('.woopt_time_to').val();
        timeVal = (from || '') + ' - ' + (to || '');
      } else if (timeShow === 'time') {
        timeVal = $t.find('.woopt_time_on').val();
      } else if (timeShow === 'weekday') {
        timeVal = $t.find('.woopt_weekday option:selected').text().trim();
      } else if (timeShow === 'monthday') {
        timeVal = $t.find('.woopt_monthday option:selected').text().trim();
      } else if (timeShow === 'weekno') {
        timeVal = $t.find('.woopt_weekno option:selected').text().trim();
      } else if (timeShow === 'monthno') {
        timeVal = $t.find('.woopt_monthno option:selected').text().trim();
      }

      if (timeType) {
        var entry = '<span class="woopt-sum-type">' + timeType + '</span>';
        if (timeVal) {
          entry += ' <span class="woopt-sum-value">' + timeVal + '</span>';
        }
        timers.push(entry);
      }
    });

    // Build HTML
    var html = '<div class="woopt-sum-section">';
    html += '<div class="woopt-sum-status active"><span class="woopt-sum-dot"></span> Active</div>';
    html += '<div class="woopt-sum-badge">' + '#' + key + '</div>';
    html += '</div>';

    html += '<div class="woopt-sum-section">';
    html += '<div class="woopt-sum-label">Action</div>';
    html += '<div class="woopt-sum-detail"><strong class="woopt-sum-type">' + actionText + '</strong>' + actionExtra + '</div>';
    html += '</div>';

    if (isGlobal && applyText) {
      html += '<div class="woopt-sum-section">';
      html += '<div class="woopt-sum-label">Apply for</div>';
      html += '<div class="woopt-sum-detail">' + applyText;
      if (applyDetails.length > 0) {
        html += ' (' + applyDetails.join(', ') + ')';
      }
      html += '</div></div>';
    }

    if (timers.length > 0) {
      html += '<div class="woopt-sum-section">';
      html += '<div class="woopt-sum-label">Time conditions</div>';
      html += '<div class="woopt-sum-conditions">';
      for (var j = 0; j < timers.length; j++) {
        var tPrefix = j > 0 ? '<span class="woopt-sum-relation">AND</span> ' : '';
        html += '<div class="woopt-sum-condition-item">' + tPrefix + timers[j] + '</div>';
      }
      html += '</div>';
      html += '</div>';
    } else {
      html += '<div class="woopt-sum-section">';
      html += '<div class="woopt-sum-label">Time conditions</div>';
      html += '<div class="woopt-sum-detail">(Always active / No conditions set)</div>';
      html += '</div>';
    }

    if (userRoles.length > 0) {
      html += '<div class="woopt-sum-section">';
      html += '<div class="woopt-sum-label">User roles</div>';
      html += '<div class="woopt-sum-detail">' + userRoles.join(', ') + '</div>';
      html += '</div>';
    }

    if ($('#woopt-summary-modal').length === 0) {
      $('body').append('<div id="woopt-summary-modal"><div class="woopt-summary-content"></div></div>');
    }

    $('#woopt-summary-modal').attr('title', actionName).find('.woopt-summary-content').html(html);
    $('#woopt-summary-modal').dialog({
      modal: true,
      width: 520,
      dialogClass: 'woopt-dialog woopt-summary-dialog',
      open: function() {
        $('.ui-widget-overlay').bind('click', function() {
          $('#woopt-summary-modal').dialog('close');
        });
      },
      buttons: {
        'Close': function() {
          $(this).dialog('close');
        }
      }
    });
  });

  $(document).on('click touch', '.woopt_expand_all', function(e) {
    e.preventDefault();

    $('.woopt_action').addClass('active');
  });

  $(document).on('click touch', '.woopt_collapse_all', function(e) {
    e.preventDefault();

    $('.woopt_action').removeClass('active');
  });

  $(document).on('click touch', '.woopt_time_remove', function(e) {
    e.preventDefault();

    if (confirm('Are you sure?')) {
      $(this).closest('.woopt_time').remove();
    }
  });

  $(document).on('click touch', '.woopt_edit', function(e) {
    var pid = $(this).attr('data-pid');
    var name = $(this).attr('data-name');

    if (!$('#woopt_edit_popup').length) {
      $('body').append('<div id=\'woopt_edit_popup\'></div>');
    }

    $('#woopt_edit_popup').html('Loading...');

    $('#woopt_edit_popup').dialog({
      minWidth: 560,
      title: '#' + pid + ' - ' + name,
      modal: true,
      dialogClass: 'wpc-dialog',
      open: function() {
        $('.ui-widget-overlay').bind('click', function() {
          $('#woopt_edit_popup').dialog('close');
        });
      },
    });

    var data = {
      action: 'woopt_edit', nonce: woopt_vars.nonce, pid: pid,
    };

    $.post(ajaxurl, data, function(response) {
      $('#woopt_edit_popup').html(response);
    });

    e.preventDefault();
  });

  $(document).on('click touch', '.woopt_edit_save', function(e) {
    $(this).addClass('disabled');
    $('.woopt_edit_message').html('...');

    var pid = $(this).attr('data-pid');
    var actions = $('.woopt_edit_data').val();

    var data = {
      action: 'woopt_edit_save',
      nonce: woopt_vars.nonce,
      pid: pid,
      actions: actions,
    };

    $.post(ajaxurl, data, function(response) {
      $('.woopt_edit_save').removeClass('disabled');
      $('.woopt_edit_message').html(response);
    });
  });

  function woopt_time_picker() {
    $('.woopt_dpk_date_time:not(.woopt_dpk_init)').wpcdpk({
      timepicker: true, onSelect: function(fd, d, dpk) {
        if (!d) {
          return;
        }

        var show = dpk.$el.closest('.woopt_time').
            find('.woopt_time_type').
            find(':selected').
            data('show');

        dpk.$el.closest('.woopt_time').
            find('.woopt_time_val').data(show, fd).val(fd).trigger('change');
      },
    }).addClass('woopt_dpk_init');

    $('.woopt_dpk_date:not(.woopt_dpk_init)').wpcdpk({
      onSelect: function(fd, d, dpk) {
        if (!d) {
          return;
        }

        var show = dpk.$el.closest('.woopt_time').
            find('.woopt_time_type').
            find(':selected').
            data('show');

        dpk.$el.closest('.woopt_time').
            find('.woopt_time_val').data(show, fd).val(fd).trigger('change');
      },
    }).addClass('woopt_dpk_init');

    $('.woopt_dpk_date_range:not(.woopt_dpk_init)').wpcdpk({
      range: true,
      multipleDatesSeparator: ' - ',
      onSelect: function(fd, d, dpk) {
        if (!d) {
          return;
        }

        var show = dpk.$el.closest('.woopt_time').
            find('.woopt_time_type').
            find(':selected').
            data('show');

        dpk.$el.closest('.woopt_time').
            find('.woopt_time_val').data(show, fd).val(fd).trigger('change');
      },
    }).addClass('woopt_dpk_init');

    $('.woopt_dpk_date_multi:not(.woopt_dpk_init)').wpcdpk({
      multipleDates: 5,
      multipleDatesSeparator: ', ',
      onSelect: function(fd, d, dpk) {
        if (!d) {
          return;
        }

        var show = dpk.$el.closest('.woopt_time').
            find('.woopt_time_type').
            find(':selected').
            data('show');

        dpk.$el.closest('.woopt_time').
            find('.woopt_time_val').data(show, fd).val(fd).trigger('change');
      },
    }).addClass('woopt_dpk_init');

    $('.woopt_dpk_time:not(.woopt_dpk_init)').wpcdpk({
      timepicker: true,
      onlyTimepicker: true,
      classes: 'only-time',
      onSelect: function(fd, d, dpk) {
        if (!d) {
          return;
        }

        var show = dpk.$el.closest('.woopt_time').
            find('.woopt_time_type').
            find(':selected').
            data('show');

        if (dpk.$el.hasClass('woopt_time_from') ||
            dpk.$el.hasClass('woopt_time_to')) {
          var time_range = dpk.$el.closest('.woopt_time').
                  find('.woopt_time_from').val() + ' - ' +
              dpk.$el.closest('.woopt_time').
                  find('.woopt_time_to').val();

          dpk.$el.closest('.woopt_time').
              find('.woopt_time_val').
              data(show, time_range).
              val(time_range).
              trigger('change');
        } else {
          dpk.$el.closest('.woopt_time').
              find('.woopt_time_val').data(show, fd).val(fd).trigger('change');
        }
      },
    }).addClass('woopt_dpk_init');
  }

  function woopt_terms_init() {
    $('.woopt_terms').each(function() {
      var $this = $(this);
      var apply = $this.closest('.woopt_action').
          find('.woopt_apply_selector').
          val();
      var taxonomy = apply.slice(6);

      $this.selectWoo({
        ajax: {
          url: ajaxurl, dataType: 'json', delay: 250, data: function(params) {
            return {
              q: params.term,
              action: 'woopt_search_term',
              taxonomy: taxonomy,
              nonce: woopt_vars.nonce,
            };
          }, processResults: function(data) {
            var options = [];
            if (data) {
              $.each(data, function(index, text) {
                options.push({id: text[0], text: text[1]});
              });
            }
            return {
              results: options,
            };
          }, cache: true,
        }, minimumInputLength: 1,
      });

      if (apply !== 'apply_all' && apply !== 'apply_variation' && apply !==
          'apply_not_variation' && apply !== 'apply_product' && apply !==
          'apply_category' && apply !== 'apply_tag' && apply !==
          'apply_combination') {
        // for terms only
        if ($this.data(apply) !== undefined && $this.data(apply) !== '') {
          $this.val(String($this.data(apply)).split(',')).change();
        } else {
          $this.val([]).change();
        }
      }
    });
  }

  function woopt_user_roles_init() {
    $('.woopt_user_roles_select').selectWoo();
  }

  function woopt_apply_terms_init() {
    $('.woopt_apply_terms').each(function() {
      var $this = $(this);
      var taxonomy = $this.closest('.woopt_apply_combination').
          find('.woopt_apply_combination_select').
          val();

      if (taxonomy === 'variation' || taxonomy === 'not_variation') {
        $this.hide();
      } else {
        $this.show().selectWoo({
          ajax: {
            url: ajaxurl, dataType: 'json', delay: 250, data: function(params) {
              return {
                q: params.term,
                action: 'woopt_search_term',
                taxonomy: taxonomy,
                nonce: woopt_vars.nonce,
              };
            }, processResults: function(data) {
              var options = [];
              if (data) {
                $.each(data, function(index, text) {
                  options.push({id: text[0], text: text[1]});
                });
              }
              return {
                results: options,
              };
            }, cache: true,
          }, minimumInputLength: 1,
        });
      }
    });
  }

  function woopt_products_init() {
    $('.woopt_apply_selector').each(function() {
      var $this = $(this);
      var $val = $this.closest('.woopt_action').find('.woopt_apply_val');
      var products = $this.closest('.woopt_action').
          find('.woopt-product-search').
          attr('data-val');

      if ($this.val() === 'apply_product') {
        $val.val(products).trigger('change');
      }
    });

    $(document.body).trigger('wc-enhanced-select-init');
  }

  function woopt_show_action($action) {
    if (typeof $action !== 'undefined') {
      var show_action = $action.find('.woopt_action_selector').val();

      $action.find('.woopt_hide').hide();
      $action.find('.woopt_show_if_' + show_action).show();

      if (show_action === 'set_saleprice') {
        var $price_base = $action.find('.woopt_action_price_base');
        var option_pr = $price_base.find('option[value="pr"]').
            data('set_saleprice');

        $price_base.find('option[value="ps"]').show();
        $price_base.find('option[value="pr"]').html(option_pr);
        $price_base.trigger('change');
      }

      if (show_action === 'set_regularprice') {
        var $price_base = $action.find('.woopt_action_price_base');
        var option_pr = $price_base.find('option[value="pr"]').
            data('set_regularprice');

        $price_base.find('option[value="ps"]').hide();
        $price_base.find('option[value="pr"]').html(option_pr);

        if ($price_base.val() === 'ps') {
          $price_base.val('pr');
        }

        $price_base.trigger('change');
      }
    } else {
      $('.woopt_action_wrap').each(function() {
        var $action = $(this);
        var show_action = $action.find('.woopt_action_selector').val();

        $action.find('.woopt_hide').hide();
        $action.find('.woopt_show_if_' + show_action).show();

        if (show_action === 'set_saleprice') {
          var $price_base = $action.find('.woopt_action_price_base');
          var option_pr = $price_base.find('option[value="pr"]').
              data('set_saleprice');

          $price_base.find('option[value="ps"]').show();
          $price_base.find('option[value="pr"]').html(option_pr);
          $price_base.trigger('change');
        }

        if (show_action === 'set_regularprice') {
          var $price_base = $action.find('.woopt_action_price_base');
          var option_pr = $price_base.find('option[value="pr"]').
              data('set_regularprice');

          $price_base.find('option[value="ps"]').hide();
          $price_base.find('option[value="pr"]').html(option_pr);

          if ($price_base.val() === 'ps') {
            $price_base.val('pr');
          }

          $price_base.trigger('change');
        }
      });
    }
  }

  function woopt_show_conditional($time) {
    if (typeof $time !== 'undefined') {
      var show = $time.find('.woopt_time_type').find(':selected').data('show');
      var $val = $time.find('.woopt_time_val');

      if ($val.data(show) !== undefined) {
        $val.val($val.data(show)).trigger('change');
      } else {
        $val.val('').trigger('change');
      }

      $time.find('.woopt_hide').hide();
      $time.find('.woopt_show_if_' + show).
          show();
    } else {
      $('.woopt_time').each(function() {
        var show = $(this).
            find('.woopt_time_type').
            find(':selected').
            data('show');
        var $val = $(this).find('.woopt_time_val');

        $val.data(show, $val.val());

        $(this).find('.woopt_hide').hide();
        $(this).find('.woopt_show_if_' + show).show();
      });
    }
  }

  function woopt_show_apply($action) {
    if (typeof $action !== 'undefined') {
      var apply = $action.find('.woopt_apply_selector').find(':selected').val();
      var apply_text = $action.find('.woopt_apply_selector').
          find(':selected').
          text();

      $action.find('.woopt_apply_text').text(apply_text);
      $action.find('.hide_apply').hide();
      $action.find('.show_if_' + apply).show();
      $action.find('.show_apply').show();
      $action.find('.hide_if_' + apply).hide();
    } else {
      $('.woopt_action').each(function() {
        var $action = $(this);
        var apply = $action.find('.woopt_apply_selector').
            find(':selected').
            val();
        var apply_text = $action.find('.woopt_apply_selector').
            find(':selected').
            text();

        $action.find('.woopt_apply_text').text(apply_text);
        $action.find('.hide_apply').hide();
        $action.find('.show_if_' + apply).show();
        $action.find('.show_apply').show();
        $action.find('.hide_if_' + apply).hide();
      });
    }
  }

  function woopt_sortable() {
    $('.woopt_actions').
        sortable({
          handle: '.woopt_action_move', placeholder: 'woopt_action_placeholder',
        });
  }

  function woopt_build_label($select) {
    if (typeof $select !== 'undefined') {
      var action = $select.find('option:selected').text();

      $select.closest('.woopt_action').
          find('.woopt_action_label_action').
          text(action);

      if ($select.closest('.woopt_action').
          find('.woopt_apply_selector').length) {
        var apply = $select.closest('.woopt_action').
            find('.woopt_apply_selector option:selected').
            text();

        $select.closest('.woopt_action').
            find('.woopt_action_label_apply').
            text(apply);
      }

      if ($select.closest('.woopt_action').
          find('.woopt_action_name_input').length) {
        var name = $select.closest('.woopt_action').
            find('.woopt_action_name_input').val();

        if (name !== '') {
          $select.closest('.woopt_action').
              find('.woopt_action_label_name').
              text(name);
        }
      }
    } else {
      $('.woopt_action_selector').each(function() {
        var $select = $(this);
        var action = $select.find('option:selected').text();

        $select.closest('.woopt_action').
            find('.woopt_action_label_action').
            text(action);

        if ($select.closest('.woopt_action').
            find('.woopt_apply_selector').length) {
          var apply = $select.closest('.woopt_action').
              find('.woopt_apply_selector option:selected').
              text();

          $select.closest('.woopt_action').
              find('.woopt_action_label_apply').
              text(apply);
        }

        if ($select.closest('.woopt_action').
            find('.woopt_action_name_input').length) {
          var name = $select.closest('.woopt_action').
              find('.woopt_action_name_input').val();

          if (name !== '') {
            $select.closest('.woopt_action').
                find('.woopt_action_label_name').
                text(name);
          }
        }
      });
    }
  }
})(jQuery);