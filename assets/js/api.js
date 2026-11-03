/**
 * @fileoverview API Client & Proxy Bridge untuk Asisten Penilaian Mandiri
 * Menghubungkan Frontend ke Backend PHP (api.php)
 */

const AppAPI = {
  /**
   * Panggilan dasar AJAX fetch ke api.php
   */
  async call(action, payload = {}) {
    const isFormData = payload instanceof FormData;
    let options = {};

    if (isFormData) {
      if (!payload.has('action')) {
        payload.append('action', action);
      }
      options = {
        method: 'POST',
        body: payload
      };
    } else {
      options = {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ action, ...payload })
      };
    }

    try {
      const response = await fetch('api.php', options);
      if (!response.ok) {
        throw new Error(`Kesalahan Server (HTTP ${response.status})`);
      }
      const json = await response.json();

      // Untuk penilaian individual, kembalikan objek json langsung agar UI dapat memproses status kegagalan terstruktur
      if (action === 'gradeSingleStudent') {
        return json;
      }

      if (json.success === false) {
        throw new Error(json.message || json.error || 'Terjadi kesalahan sistem.');
      }

      return json;
    } catch (err) {
      console.error(`[API Error: ${action}]`, err);
      throw err;
    }
  },

  // Helper File Upload
  async uploadSubmissionsZip(taskId, classId, fileOrFiles) {
    const formData = new FormData();
    formData.append('action', 'uploadSubmissionsZip');
    formData.append('taskId', taskId);
    formData.append('classId', classId);

    if (fileOrFiles instanceof FileList || Array.isArray(fileOrFiles)) {
      for (let i = 0; i < fileOrFiles.length; i++) {
        formData.append('files[]', fileOrFiles[i]);
      }
    } else if (fileOrFiles) {
      formData.append('files[]', fileOrFiles);
    }
    return this.call('uploadSubmissionsZip', formData);
  },

  async uploadTaskQuestion(taskId, file) {
    const formData = new FormData();
    formData.append('action', 'uploadTaskQuestion');
    formData.append('taskId', taskId);
    formData.append('questionFile', file);
    return this.call('uploadTaskQuestion', formData);
  },

  async getGoogleOAuthStatus() {
    return this.call('getGoogleOAuthStatus');
  },

  async getGoogleAuthUrl(redirectUri = '') {
    return this.call('getGoogleAuthUrl', { redirectUri });
  },

  async disconnectGoogleDrive() {
    return this.call('disconnectGoogleDrive');
  },

  async syncTaskDriveFolder(taskId, classId) {
    return this.call('syncTaskDriveFolder', { taskId, classId });
  }
};

window.AppAPI = AppAPI;

/**
 * Pemetaan method name GAS ke API Gateway
 */
async function dispatchGasApiMethod(methodName, args) {
  switch (methodName) {
    case 'apiGetInitialData':
      return await AppAPI.call('getInitialData');

    case 'apiGetCourses':
      const coursesRes = await AppAPI.call('getCourses');
      return coursesRes.courses || [];

    case 'apiSaveCourse':
      const saveCourseRes = await AppAPI.call('saveCourse', { data: args[0] });
      return saveCourseRes.data;

    case 'apiDeleteCourse':
      return await AppAPI.call('deleteCourse', { courseId: args[0] });

    case 'apiGetClasses':
      const classesRes = await AppAPI.call('getClasses', { courseId: args[0] || null });
      return classesRes.classes || [];

    case 'apiSaveClass':
      const saveClassRes = await AppAPI.call('saveClass', { data: args[0] });
      return saveClassRes.data;

    case 'apiDeleteClass':
      return await AppAPI.call('deleteClass', { classId: args[0] });

    case 'apiGetStudents':
      const studentsRes = await AppAPI.call('getStudents', { classId: args[0] });
      return studentsRes.students || [];

    case 'apiSaveStudentsBulk':
      return await AppAPI.call('saveStudentsBulk', { classId: args[0], rawText: args[1] });

    case 'apiSaveStudent':
      const saveStudentRes = await AppAPI.call('saveStudent', { classId: args[0], data: args[1] });
      return saveStudentRes.data;

    case 'apiDeleteStudent':
      return await AppAPI.call('deleteStudent', { studentId: args[0] });

    case 'apiGetTasks':
      const tasksRes = await AppAPI.call('getTasks', { courseIdOrClassId: args[0] });
      return tasksRes.tasks || [];

    case 'apiGetTasksByCourse':
      const tasksCourseRes = await AppAPI.call('getTasksByCourse', { courseId: args[0] });
      return tasksCourseRes.tasks || [];

    case 'apiGetTasksByClass':
      const tasksClassRes = await AppAPI.call('getTasksByClass', { classId: args[0] });
      return tasksClassRes.tasks || [];

    case 'apiGetTaskById':
      const taskRes = await AppAPI.call('getTaskById', { taskId: args[0], classId: args[1] || null });
      return taskRes.task;

    case 'apiSaveTask':
      const saveTaskRes = await AppAPI.call('saveTask', { data: args[0] });
      return saveTaskRes.data;

    case 'apiDeleteTask':
      return await AppAPI.call('deleteTask', { taskId: args[0] });

    case 'apiGetGradesByTask':
      const gradesRes = await AppAPI.call('getGradesByTask', { taskId: args[0], classId: args[1] || '' });
      if (typeof window !== 'undefined' && window.appState) {
        window.appState.uploadedSubmissionCount = gradesRes.uploadedCount ?? 0;
      }
      const gradesList = gradesRes.grades || [];
      gradesList.uploadedCount = gradesRes.uploadedCount ?? 0;
      return gradesList;

    case 'apiGradeSingleStudent':
      try {
        return await AppAPI.call('gradeSingleStudent', {
          taskId: args[0],
          classId: args[1],
          studentNim: args[2]
        });
      } catch (err) {
        return {
          success: false,
          nim: args[2],
          error: err.message,
          status: 'Gagal: ' + err.message,
          catatan_ai: '[ERROR JARINGAN/SERVER]:\n' + err.message
        };
      }

    case 'apiResetFailedGrades':
      return await AppAPI.call('resetFailedGrades', { taskId: args[0], classId: args[1] });

    case 'apiSaveGradeManual':
      const saveGradeRes = await AppAPI.call('saveGradeManual', { gradeData: args[0] });
      return saveGradeRes.data;

    case 'apiGetClassReport':
      const reportRes = await AppAPI.call('getClassReport', { classId: args[0] });
      return reportRes.report;

    case 'apiGetClassFullExportData':
      return await AppAPI.call('getClassFullExportData', { classId: args[0] });

    case 'apiValidateDriveFolder':
      return {
        success: true,
        folderId: args[0] ? args[0].trim() : '',
        message: 'Folder terhubung (Mode Web Hosting).'
      };

    case 'apiValidateTaskFile':
      return await AppAPI.call('validateTaskFile', { ref: args[0] });

    case 'apiExtractRubricFromTask':
      return await AppAPI.call('extractRubricFromTask', {
        taskId: '',
        taskFileRef: args[0],
        model: args[1] || 'gemini-3.5-flash-lite'
      });

    case 'apiGetSettings':
      const settingsRes = await AppAPI.call('getSettings');
      return settingsRes.settings;

    case 'apiSaveSettings':
      return await AppAPI.call('saveSettings', { data: args[0] });

    case 'apiTestConnection':
      return await AppAPI.call('testConnection', { apiKey: args[0], model: args[1] });

    case 'apiResetDatabase':
      return await AppAPI.call('resetDatabase');

    case 'apiGetGoogleOAuthStatus':
      return await AppAPI.getGoogleOAuthStatus();

    case 'apiGetGoogleAuthUrl':
      return await AppAPI.getGoogleAuthUrl(args[0] || '');

    case 'apiDisconnectGoogleDrive':
      return await AppAPI.disconnectGoogleDrive();

    case 'apiSyncTaskDriveFolder':
      return await AppAPI.syncTaskDriveFolder(args[0], args[1]);

    default:
      return await AppAPI.call(methodName, { args });
  }
}

/**
 * Polyfill google.script.run untuk kompatibilitas penuh kode antarmuka eksisting
 */
window.google = window.google || {};
window.google.script = window.google.script || {};

function createScriptRunProxy(successFn = null, failureFn = null) {
  return new Proxy({
    withSuccessHandler(fn) {
      return createScriptRunProxy(fn, failureFn);
    },
    withFailureHandler(fn) {
      return createScriptRunProxy(successFn, fn);
    }
  }, {
    get(target, prop) {
      if (prop === 'withSuccessHandler' || prop === 'withFailureHandler') {
        return target[prop];
      }
      return async function(...args) {
        try {
          const result = await dispatchGasApiMethod(prop, args);
          if (typeof successFn === 'function') {
            successFn(result);
          }
          return result;
        } catch (err) {
          if (typeof failureFn === 'function') {
            failureFn(err);
          } else {
            console.error(`[API Error in ${prop}]:`, err);
          }
        }
      };
    }
  });
}

window.google.script.run = createScriptRunProxy();
